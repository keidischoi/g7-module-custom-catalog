<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\Collector;
use Modules\Custom\Catalog\Services\PhotoService;

/**
 * 0.2.3 제조사 로고 — 카드 · 상세 · 제조사 칩에 보임.
 *
 * 로고는 관리자가 넣음 (설정 logos: 정리한 제조사 이름 → 주소):
 *   ① 직접 올리기   ② 「홈페이지에서 가져오기」 — 제조사 홈페이지의 아이콘(apple-touch-icon · icon)을 받아 줄여 저장 (안 되면 구글의 사이트 아이콘 서비스)
 * 로고가 없는 제조사는 화면이 머리글자 배지를 그림.
 * (예전 resources/assets/logo-*.jpg 는 이름만 적힌 그림이라 로고로 쓰지 않음 — makers.json 은 홈페이지 주소를 찾는 데만 씀)
 */
final class Logos
{
    /** @var array<string, string>|null 정리한 이름 → 홈페이지 */
    private static ?array $homes = null;

    /** 이 제조사의 로고 주소 (없으면 빈 글) */
    public static function of(string $brand, ?array $own = null): string
    {
        $n = CatalogService::norm($brand);
        $own ??= (array) Settings::get('logos');

        return $n !== '' && ! empty($own[$n]) ? (string) $own[$n] : '';
    }

    /** @return array<string, string> 정리한 이름 → 주소 */
    public static function map(): array
    {
        return array_map('strval', (array) Settings::get('logos'));
    }

    /** @return list<string> 카탈로그에 있는 제조사 */
    public static function brands(): array
    {
        Schema::ensure();
        $all = [];
        foreach ([Schema::EQUIPMENT, Schema::MATERIALS] as $t) {
            foreach (DB::table($t)->where('status', 'active')->distinct()->pluck('brand') as $b) {
                $b = trim((string) $b);
                if ($b !== '' && $b !== '종류') {
                    $all[CatalogService::norm($b)] ??= $b;
                }
            }
        }
        $list = array_values($all);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /** 관리자 화면용 @return list<array{brand: string, logo: string, home: string}> */
    public static function adminList(): array
    {
        $own = (array) Settings::get('logos');

        return array_map(static fn ($b) => ['brand' => $b, 'logo' => self::of($b, $own), 'home' => self::home($b)], self::brands());
    }

    /** 제조사 홈페이지 — makers.json → 그 제조사 항목에 적힌 「제품 공식 페이지」의 첫 화면 */
    public static function home(string $brand): string
    {
        $n = CatalogService::norm($brand);
        if (self::$homes === null) {
            self::$homes = [];
            $j = json_decode((string) @file_get_contents(dirname(__DIR__, 2).'/resources/assets/makers.json'), true);
            foreach (is_array($j) ? $j : [] as $m) {
                if (is_array($m) && ! empty($m['match']) && ! empty($m['homepage'])) {
                    self::$homes[CatalogService::norm((string) $m['match'])] = (string) $m['homepage'];
                }
            }
        }
        foreach (self::$homes as $match => $url) {
            if ($n === $match || (mb_strlen($match) >= 4 && str_starts_with($n, $match)) || (mb_strlen($n) >= 4 && str_starts_with($match, $n))) {
                return $url;
            }
        }
        try {
            foreach ([Schema::EQUIPMENT, Schema::MATERIALS] as $t) {
                foreach (DB::table($t)->where('status', 'active')->where('brand', $brand)->whereNotNull('homepage_url')->where('homepage_url', '!=', '')->limit(3)->pluck('homepage_url') as $u) {
                    $p = parse_url((string) $u);
                    if (! empty($p['scheme']) && ! empty($p['host'])) {
                        return $p['scheme'].'://'.$p['host'];
                    }
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /** 로고 올리기 (긴 변 320px JPEG · 흰 바탕) */
    public static function set(string $brand, string $raw): string
    {
        $n = CatalogService::norm($brand);
        $jpg = $n !== '' ? PhotoService::fit($raw, 320, 88) : null;
        if ($jpg === null) {
            throw new \InvalidArgumentException('로고 그림을 읽지 못했어요 (JPG · PNG · WebP).');
        }
        $name = 'logo-'.substr(md5($n), 0, 10).'-'.substr(md5($jpg), 0, 6).'.jpg';
        PhotoService::store($name, $jpg);
        $own = (array) Settings::get('logos');
        $own[$n] = '/api/modules/custom-catalog/images/'.$name;
        Settings::save(['logos' => $own]);

        return $own[$n];
    }

    /**
     * 제조사 홈페이지에서 아이콘을 받아 로고로 — apple-touch-icon(대개 180px 정사각) → icon(가장 큰 PNG) 순서
     *
     * @throws \InvalidArgumentException 홈페이지를 모르거나 쓸 만한 아이콘이 없을 때
     */
    public static function fetch(string $brand): string
    {
        $home = self::home($brand);
        if ($home === '') {
            throw new \InvalidArgumentException('이 제조사의 홈페이지 주소를 몰라요 — 그 제조사 항목에 「제품 공식 페이지」를 적거나, 로고를 직접 올려 주세요.');
        }
        $html = (string) Collector::page($home, ['Accept' => 'text/html']);
        $cands = [];
        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (! preg_match('#\brel=["\']?([^"\'>]+)#i', $tag, $rel) || ! preg_match('#\bhref=["\']?([^"\'\s>]+)#i', $tag, $href)) {
                    continue;
                }
                $r = strtolower($rel[1]);
                $size = preg_match('#\bsizes=["\']?(\d+)x#i', $tag, $sz) ? (int) $sz[1] : 0;
                if (str_contains($r, 'apple-touch-icon')) {
                    $cands[] = [1000 + $size, $href[1]];
                } elseif (preg_match('#(^|\s)(shortcut\s+)?icon(\s|$)#', $r) && ! preg_match('#\.(ico|svg)(\?|$)#i', $href[1])) {
                    $cands[] = [$size, $href[1]];
                }
            }
        }
        $cands[] = [500, '/apple-touch-icon.png'];
        usort($cands, static fn ($a, $b) => $b[0] <=> $a[0]);
        $base = parse_url($home);
        $cands = array_slice($cands, 0, 5);
        // 홈페이지가 로봇을 막거나 아이콘이 .ico · .svg 뿐일 때 — 구글의 사이트 아이콘 서비스 (PNG 128px)
        $cands[] = [0, 'https://www.google.com/s2/favicons?sz=128&domain_url='.rawurlencode($base['scheme'].'://'.$base['host'])];
        foreach ($cands as [$score, $href]) {
            $href = html_entity_decode($href);
            $url = str_starts_with($href, '//') ? 'https:'.$href : (preg_match('#^https?://#i', $href) ? $href : $base['scheme'].'://'.$base['host'].'/'.ltrim($href, '/'));
            $got = PhotoService::fetch($url);
            $size = $got ? PhotoService::size($got['body']) : null;
            if ($size && min($size) >= 48) {
                return self::set($brand, $got['body']);
            }
        }

        throw new \InvalidArgumentException('홈페이지('.parse_url($home, PHP_URL_HOST).')에서 쓸 만한 아이콘을 찾지 못했어요 — 로고를 직접 올려 주세요.');
    }

    /** 로고 지우기 */
    public static function clear(string $brand): void
    {
        $own = (array) Settings::get('logos');
        unset($own[CatalogService::norm($brand)]);
        Settings::save(['logos' => $own]);
    }
}
