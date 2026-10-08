<?php

namespace Modules\Custom\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Custom\Catalog\Support\Schema;
use Modules\Custom\Catalog\Support\Settings;

/**
 * 0.2.0 사진 — 올리기 · 주소로 가져오기 · 지우기 · 대표 정하기.
 * 저장: local 디스크 modules/custom-catalog/images/ (JPEG, 긴 변 설정값) → /api/modules/custom-catalog/images/{이름}
 * 대표 사진 = 순서가 가장 앞인 사진 (표의 image_url 에도 적어 둠 — 목록이 빨리 그리게).
 */
final class PhotoService
{
    public const MAX = 12;

    public const DIR = 'modules/custom-catalog/images/';

    /** 목록 카드용 작은 사진 (긴 변) — 원본 옆에 「이름-t.jpg」 로 같이 저장 */
    public const THUMB_PX = 480;

    /** 목록에서 쓸 작은 사진 주소 (올린 사진만 — 예전 사진은 그대로) */
    public static function thumb(string $url): string
    {
        return preg_match('#^/api/modules/custom-catalog/images/[^/]+(?<!-t)\.jpg$#', $url) ? substr($url, 0, -4).'-t.jpg' : $url;
    }

    /** 테스트용: 이 폴더에 그냥 파일로 */
    public static ?string $dirOverride = null;

    /** 테스트용: fn(string $url): ?array{body: string, type: string} */
    public static $fetcher = null;

    private static function itemType(string $type): string
    {
        return $type === 'materials' ? 'material' : 'equipment';
    }

    /** @return list<array<string, mixed>> */
    public function of(string $type, string $key): array
    {
        return DB::table(Schema::PHOTOS)->where('item_type', self::itemType($type))->where('item_key', $key)->orderBy('sort')->orderBy('id')->get()
            ->map(static fn ($p) => ['id' => (int) $p->id, 'url' => (string) $p->url, 'credit' => (string) ($p->credit ?? ''), 'source_url' => (string) ($p->source_url ?? '')])->all();
    }

    /**
     * 사진 내용(바이트) 한 장 더하기
     *
     * @return array{id: int, url: string}
     */
    public function add(string $type, string $key, string $raw, string $sourceUrl = '', string $credit = ''): array
    {
        Schema::ensure();
        $it = self::itemType($type);
        $table = Schema::table($type);
        if (! DB::table($table)->where('key', $key)->exists()) {
            throw new \InvalidArgumentException('항목이 없어요.');
        }
        // 예전 대표 사진(image_url 만 있고 사진 표에는 없는 것)을 첫 장으로 옮겨 둠 — 새 사진을 더해도 사라지지 않게
        $have = DB::table(Schema::PHOTOS)->where('item_type', $it)->where('item_key', $key)->count();
        $old = (string) (DB::table($table)->where('key', $key)->value('image_url') ?? '');
        if ($have === 0 && $old !== '') {
            DB::table(Schema::PHOTOS)->insert(['item_type' => $it, 'item_key' => $key, 'url' => $old, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $have = 1;
        }
        if ($have >= self::MAX) {
            throw new \InvalidArgumentException('사진은 '.self::MAX.'장까지예요.');
        }
        $jpeg = self::fit($raw, (int) Settings::get('photo_px'), (int) Settings::get('photo_quality'));
        if ($jpeg === null) {
            throw new \InvalidArgumentException('사진을 읽지 못했어요 (JPG · PNG · WebP).');
        }
        $hash = substr(md5($jpeg), 0, 12);
        $name = substr(preg_replace('/[^a-z0-9-]/', '', $key) ?? 'item', 0, 60).'-'.$hash.'.jpg';
        $url = '/api/modules/custom-catalog/images/'.$name;
        if ($dup = DB::table(Schema::PHOTOS)->where('item_type', $it)->where('item_key', $key)->where('url', $url)->first()) {
            return ['id' => (int) $dup->id, 'url' => $url];   // 같은 사진
        }
        self::put($name, $jpeg);
        self::put(substr($name, 0, -4).'-t.jpg', self::fit($jpeg, self::THUMB_PX, 78) ?? $jpeg);
        $sort = (int) DB::table(Schema::PHOTOS)->where('item_type', $it)->where('item_key', $key)->max('sort') + 1;
        $id = (int) DB::table(Schema::PHOTOS)->insertGetId(['item_type' => $it, 'item_key' => $key, 'url' => $url, 'sort' => $sort,
            'source_url' => $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : null, 'credit' => $credit !== '' ? mb_substr($credit, 0, 200) : null,
            'created_at' => now(), 'updated_at' => now()]);
        $this->sync($type, $key);

        return ['id' => $id, 'url' => $url];
    }

    /** 주소에서 받아 더하기 @return array{id: int, url: string} */
    public function addFromUrl(string $type, string $key, string $url, string $credit = '', string $pageUrl = ''): array
    {
        $got = self::fetch($url);
        if (! $got) {
            throw new \InvalidArgumentException('사진을 받지 못했어요 — 주소가 사진이 아니거나 막혀 있어요.');
        }

        return $this->add($type, $key, $got['body'], $pageUrl !== '' ? $pageUrl : $url, $credit);
    }

    public function delete(int $id): void
    {
        $p = DB::table(Schema::PHOTOS)->where('id', $id)->first();
        if (! $p) {
            return;
        }
        DB::table(Schema::PHOTOS)->where('id', $id)->delete();
        if (str_contains((string) $p->url, '/images/') && ! DB::table(Schema::PHOTOS)->where('url', $p->url)->exists()) {
            self::unlink(basename((string) $p->url));
            self::unlink(substr(basename((string) $p->url), 0, -4).'-t.jpg');
        }
        $this->sync($p->item_type === 'material' ? 'materials' : 'equipment', (string) $p->item_key);
    }

    /** 대표로 (맨 앞으로) */
    public function main(int $id): void
    {
        $p = DB::table(Schema::PHOTOS)->where('id', $id)->first();
        if (! $p) {
            return;
        }
        $min = (int) DB::table(Schema::PHOTOS)->where('item_type', $p->item_type)->where('item_key', $p->item_key)->min('sort');
        DB::table(Schema::PHOTOS)->where('item_type', $p->item_type)->where('item_key', $p->item_key)->increment('sort');
        DB::table(Schema::PHOTOS)->where('id', $id)->update(['sort' => max(0, $min), 'updated_at' => now()]);
        $this->sync($p->item_type === 'material' ? 'materials' : 'equipment', (string) $p->item_key);
    }

    /** 항목을 아주 지울 때 */
    public function purge(string $type, string $key): void
    {
        foreach (DB::table(Schema::PHOTOS)->where('item_type', self::itemType($type))->where('item_key', $key)->pluck('id') as $id) {
            $this->delete((int) $id);
        }
    }

    private function sync(string $type, string $key): void
    {
        $first = DB::table(Schema::PHOTOS)->where('item_type', self::itemType($type))->where('item_key', $key)->orderBy('sort')->orderBy('id')->value('url');
        DB::table(Schema::table($type))->where('key', $key)->update(['image_url' => $first, 'updated_at' => now()]);
    }

    /**
     * 주소에서 사진 받기 — http(s) 만 · 안쪽 주소(127. · 10. · 192.168. …)는 막음 · 8MB 까지
     *
     * @return array{body: string, type: string}|null
     */
    public static function fetch(string $url): ?array
    {
        if (is_callable(self::$fetcher)) {
            return (self::$fetcher)($url);
        }
        if (! self::safeUrl($url)) {
            return null;
        }
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; G7Catalog/0.2)', 'Accept' => 'image/*,*/*;q=0.5'])
                ->connectTimeout(6)->timeout(20)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']]])->get($url);
        } catch (\Throwable) {
            return null;
        }
        $body = (string) $res->body();
        if (! $res->successful() || $body === '' || strlen($body) > 8_000_000) {
            return null;
        }

        return ['body' => $body, 'type' => (string) $res->header('Content-Type')];
    }

    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        if (! is_array($p) || ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
            return false;
        }
        $host = strtolower((string) $p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    /** [가로, 세로] — 사진이 아니면 null @return array{0: int, 1: int}|null */
    public static function size(string $raw): ?array
    {
        $i = @getimagesizefromstring($raw);

        return $i && in_array($i[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) ? [(int) $i[0], (int) $i[1]] : null;
    }

    /** JPEG 로 (긴 변 $px 까지) — 사진이 아니면 null */
    public static function fit(string $raw, int $px = 1100, int $quality = 82): ?string
    {
        $size = self::size($raw);
        if (! $size) {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            return str_starts_with($raw, "\xFF\xD8") && strlen($raw) <= 1_500_000 ? $raw : null;
        }
        $im = @imagecreatefromstring($raw);
        if (! $im) {
            return null;
        }
        [$w, $h] = $size;
        $scale = min(1, $px / max($w, $h, 1));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // 투명 배경 → 흰색
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($out, true);   // 점점 또렷해지는 JPEG — 조금 더 작고 느린 망에서 먼저 보임
        ob_start();
        imagejpeg($out, null, max(40, min(95, $quality)));
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($out);

        return $jpeg !== '' ? $jpeg : null;
    }

    private static function put(string $name, string $body): void
    {
        if (self::$dirOverride !== null) {
            if (! is_dir(self::$dirOverride)) {
                @mkdir(self::$dirOverride, 0775, true);
            }
            file_put_contents(self::$dirOverride.'/'.$name, $body);

            return;
        }
        Storage::disk('local')->put(self::DIR.$name, $body);
    }

    private static function unlink(string $name): void
    {
        try {
            if (self::$dirOverride !== null) {
                @unlink(self::$dirOverride.'/'.$name);
            } else {
                Storage::disk('local')->delete(self::DIR.$name);
            }
        } catch (\Throwable) {
        }
    }

    /** 사진 파일 내용 (없으면 null) */
    public static function read(string $name): ?string
    {
        $name = basename($name);
        $got = self::readRaw($name);
        // 작은 사진이 없으면(0.2.0 전에 올린 것) 원본에서 한 번 만들어 둠
        if ($got === null && str_ends_with($name, '-t.jpg') && ($full = self::readRaw(substr($name, 0, -6).'.jpg')) !== null) {
            $got = self::fit($full, self::THUMB_PX, 78) ?? $full;
            try {
                self::put($name, $got);
            } catch (\Throwable) {
            }
        }

        return $got;
    }

    private static function readRaw(string $name): ?string
    {
        if (self::$dirOverride !== null) {
            return is_file(self::$dirOverride.'/'.$name) ? (string) file_get_contents(self::$dirOverride.'/'.$name) : null;
        }

        return Storage::disk('local')->exists(self::DIR.$name) ? (string) Storage::disk('local')->get(self::DIR.$name) : null;
    }
}
