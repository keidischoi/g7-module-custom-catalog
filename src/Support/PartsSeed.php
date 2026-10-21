<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\PhotoService;

/**
 * 0.3.2 부품 처음 자료 넣기 (마이그레이션에서) — resources/assets/parts-seed*.json
 *  - 이미 있는 부품(제조사 · 이름 같음)은 건너뜀
 *  - 맞는 프린터: 카탈로그에 있으면 잇고, 없으면 이름만으로 새로 넣고 이음 (Combo 같은 묶음 이름은 빼고)
 *  - 사진: 제품 페이지의 대표 사진을 출처와 함께 (못 받으면 건너뜀 · 한 번에 $seconds 초까지)
 *  - $replace: 예전 처음 자료 이름 — 회원 표 · 후기 · 구매처가 없으면 지움 (더 정확한 것으로 바꿀 때)
 */
final class PartsSeed
{
    /** @param list<string> $replace @return array{added: int, photos: int} */
    public static function load(string $file, array $replace = [], int $seconds = 240): array
    {
        Schema::ensure();
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($j['items'] ?? null)) {
            return ['added' => 0, 'photos' => 0];
        }
        if ($replace) {
            foreach (DB::table(Schema::PARTS)->where('source', 'seed')->whereIn('name', $replace)->get() as $o) {
                $used = DB::table(Schema::VOTES)->where('part_key', $o->key)->exists() || DB::table(Schema::REVIEWS)->where('part_key', $o->key)->exists() || DB::table(Schema::LINKS)->where('part_key', $o->key)->exists();
                if (! $used) {
                    DB::table(Schema::FITS)->where('part_key', $o->key)->delete();
                    DB::table(Schema::PARTS)->where('id', $o->id)->delete();
                }
            }
        }
        $svc = new CatalogService();
        $ph = new PhotoService();
        $eq = DB::table(Schema::EQUIPMENT)->orderByRaw("case when status = 'active' then 0 else 1 end")->get(['key', 'brand', 'model']);   // 0.3.3 보이는 항목 먼저
        $have = DB::table(Schema::PARTS)->get(['brand', 'name'])->map(static fn ($p) => CatalogService::norm($p->brand).'|'.CatalogService::norm($p->name))->flip();
        $budget = microtime(true) + $seconds;
        $added = 0;
        $photos = 0;
        foreach ($j['items'] as $it) {
            $nk = CatalogService::norm($it['brand'] ?? '').'|'.CatalogService::norm($it['name'] ?? '');
            if (isset($have[$nk])) {
                continue;
            }
            try {
                $key = $svc->save('parts', ['kind' => $it['kind'], 'brand' => $it['brand'], 'title' => $it['name'], 'summary' => $it['summary'] ?? '', 'homepage_url' => $it['url'] ?? '',
                    'values' => $it['values'] ?? [], 'facts' => $it['facts'] ?? []], false, 'seed')['key'];
            } catch (\Throwable) {
                continue;
            }
            $have[$nk] = true;
            $added++;
            foreach ($it['fits'] ?? [] as $f) {
                [$b, $m] = $f;
                $pk = self::printer($eq, (string) $b, (string) $m);
                if ($pk === null && ! preg_match('/combo|^x1$/i', (string) $m)) {
                    try {
                        $pk = $svc->save('equipment', ['kind' => in_array($f[4] ?? 'fdm', ['fdm', 'sla', 'dlp'], true) ? ($f[4] ?? 'fdm') : 'fdm', 'brand' => $b, 'title' => $m], false, 'seed')['key'];   // 0.3.4 [제조사, 모델, 상태, 메모, 프린터 종류]
                        $eq->push((object) ['key' => $pk, 'brand' => $b, 'model' => $m]);
                    } catch (\Throwable) {
                        $pk = null;
                    }
                }
                if ($pk !== null) {
                    $st = in_array($f[2] ?? 'fit', ['fit', 'mod', 'no'], true) ? ($f[2] ?? 'fit') : 'fit';
                    DB::table(Schema::FITS)->insertOrIgnore(['part_key' => $key, 'printer_key' => $pk, 'status' => $st === 'fit' ? null : $st, 'approved' => true,
                        'note' => isset($f[3]) ? mb_substr((string) $f[3], 0, 300) : null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if (! empty($it['image'])) {
                $ok = false;
                if (microtime(true) < $budget) {
                    try {
                        $ph->addFromUrl('parts', $key, (string) $it['image'], (string) ($it['credit'] ?? ''), (string) ($it['url'] ?? ''));
                        $ok = true;
                    } catch (\Throwable) {
                    }
                }
                if (! $ok) {
                    // 시간이 넘었거나 받지 못하면 — 공식 스토어 사진 주소를 그대로 (관리자가 나중에 받아 둘 수 있음)
                    DB::table(Schema::PHOTOS)->insert(['item_type' => 'part', 'item_key' => $key, 'url' => mb_substr((string) $it['image'], 0, 300), 'sort' => 0,
                        'credit' => mb_substr((string) ($it['credit'] ?? ''), 0, 200) ?: null, 'source_url' => mb_substr((string) ($it['url'] ?? ''), 0, 500) ?: null, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table(Schema::PARTS)->where('key', $key)->update(['image_url' => mb_substr((string) $it['image'], 0, 300)]);
                }
                $photos++;
            }
        }

        return ['added' => $added, 'photos' => $photos];
    }

    /** 카탈로그 프린터 찾기 — 제조사가 같고(앞부분) 모델 이름이 같거나 「Original Prusa MK4S」 처럼 끝이 같음 */
    public static function printer(iterable $eq, string $brand, string $model): ?string
    {
        $nb = CatalogService::norm($brand);
        $nm = CatalogService::norm($model);
        $best = null;
        foreach ($eq as $e) {
            $eb = CatalogService::norm($e->brand);
            if (! ($eb === $nb || str_starts_with($eb, $nb) || str_starts_with($nb, $eb))) {
                continue;
            }
            $em = CatalogService::norm($e->model);
            if ($em === $nm) {
                return (string) $e->key;
            }
            if ($best === null && strlen($nm) >= 2 && str_ends_with($em, $nm) && preg_match('/^(original)?(prusa)?(creality)?$/', substr($em, 0, -strlen($nm)))) {
                $best = (string) $e->key;
            }
        }

        return $best;
    }
}
