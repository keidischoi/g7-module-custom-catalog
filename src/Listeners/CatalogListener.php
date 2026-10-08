<?php

namespace Modules\Custom\Catalog\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Api\Catalog;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 다른 확장과 잇는 곳
 *  - 홈 디자인 통합 검색(custom-home_design.search.providers): 「카탈로그」 탭 — 장비 · 재료를 제조사 · 모델 · 재료 이름으로
 *  - 홈 칸(custom-home_design.home.sections): 새로 들어온 장비 · 재료
 *  - 헤더 메뉴 스크립트
 * 인기 검색어(custom-popular_search)는 화면에서 검색할 때 남김 (scope catalog).
 */
class CatalogListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        $spec = ['priority' => 50, 'type' => 'filter', 'sync' => true];

        return [
            'custom-home_design.search.providers' => ['method' => 'searchProviders', 'priority' => 16, 'type' => 'filter'],
            'custom-home_design.home.sections' => ['method' => 'homeSections', 'priority' => 36, 'type' => 'filter'],
            'core.layout.filter_child_data' => ['method' => 'scripts'] + $spec,
            'core.layout.filter_merged' => ['method' => 'scripts'] + $spec,
            'core.layout_extension.after_apply' => ['method' => 'scripts'] + $spec,
        ];
    }

    public function handle(...$args): void {}

    /* ── 통합 검색 ── */

    public function searchProviders(mixed $list = []): array
    {
        $list = is_array($list) ? $list : [];
        $list['catalog'] = [
            'label' => '카탈로그',
            'icon' => 'cube',
            'search' => static fn (string $q, int $page, int $perPage, string $sort = 'relevance'): array => self::siteSearch($q, $page, $perPage, $sort),
            'count' => static fn (string $q): int => (int) self::siteSearch($q, 1, 1, 'relevance')['total'],
        ];

        return $list;
    }

    /** @return array{total: int, items: list<array<string, mixed>>, last_page: int, has_more_pages: bool} */
    public static function siteSearch(string $q, int $page, int $perPage, string $sort): array
    {
        $empty = ['total' => 0, 'items' => [], 'last_page' => 1, 'has_more_pages' => false];
        try {
            $q = trim(mb_substr(ltrim($q, '#'), 0, 60));
            if ($q === '') {
                return $empty;
            }
            $svc = new CatalogService();
            $perPage = max(1, min(50, $perPage));
            $all = [];
            $total = 0;
            // 장비 먼저, 그다음 재료 — 두 표를 이어서 쪽을 나눔
            $skip = (max(1, $page) - 1) * $perPage;
            foreach (['equipment', 'materials'] as $type) {
                $n = $svc->list(['type' => $type, 'q' => $q, 'per' => 1])['total'];
                if (count($all) < $perPage && $skip < $n) {
                    $need = $perPage - count($all);
                    $rows = $svc->list(['type' => $type, 'q' => $q, 'per' => min(200, $skip + $need), 'sort' => $sort === 'latest' ? 'new' : ''])['items'];
                    $all = array_merge($all, array_slice($rows, $skip, $need));
                }
                $skip = max(0, $skip - $n);
                $total += $n;
            }
            $items = array_map(static fn ($c) => self::row($c, $q, 'catalog'), $all);
            $last = max(1, (int) ceil($total / $perPage));

            return ['total' => $total, 'items' => $items, 'last_page' => $last, 'has_more_pages' => $page < $last];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * 0.2.9 한 표만 — 홈 디자인 통합 검색의 「3D 장비」(equipment) · 「필라멘트 · 레진」(materials) 탭이 이것을 부름.
     * 종류 이름(「필라멘트」 · 「레진 프린터」)으로도 찾고, 사진 · 제목 · 주소를 카탈로그 화면과 같게.
     *
     * @return array{total: int, items: list<array<string, mixed>>, last_page: int, has_more_pages: bool}
     */
    public static function searchType(string $type, string $q, int $page, int $perPage, string $sort = 'relevance', string $category = ''): array
    {
        $empty = ['total' => 0, 'items' => [], 'last_page' => 1, 'has_more_pages' => false];
        try {
            $q = trim(mb_substr(ltrim($q, '#'), 0, 60));
            if ($q === '' || ! in_array($type, ['equipment', 'materials'], true)) {
                return $empty;
            }
            $perPage = max(1, min(50, $perPage));
            $page = max(1, $page);
            $r = (new CatalogService())->list(['type' => $type, 'q' => $q, 'per' => $perPage, 'page' => $page, 'sort' => $sort === 'latest' ? 'new' : ($sort === 'oldest' ? 'name' : '')]);
            if ($page > $r['pages']) {
                return ['total' => $r['total'], 'items' => [], 'last_page' => $r['pages'], 'has_more_pages' => false];
            }
            $cat = $category !== '' ? $category : ($type === 'materials' ? 'filament' : 'catalog');

            return ['total' => $r['total'], 'items' => array_map(static fn ($c) => self::row($c, $q, $cat), $r['items']), 'last_page' => $r['pages'], 'has_more_pages' => $page < $r['pages']];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** 검색 결과 한 줄 (홈 디자인 통합 검색 모양) @param array<string, mixed> $c 카드 */
    private static function row(array $c, string $q, string $category): array
    {
        $title = trim($c['brand'].' '.$c['title']);
        $excerpt = implode(' · ', $c['chips']);

        return [
            'id' => $c['key'], 'category' => $category,
            'title' => $title, 'title_highlighted' => self::mark($title, $q),
            'excerpt' => $excerpt, 'excerpt_highlighted' => self::mark($excerpt, $q),
            'url' => '/catalog/'.$c['key'], 'thumbnail' => $c['image'], 'image_url' => $c['image'], 'has_thumbnail' => $c['image'] !== '',
            'badge' => $c['kind_label'], 'date' => '', 'tags' => [],
        ];
    }

    private static function mark(string $text, string $q): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 5) as $w) {
            $needle = htmlspecialchars($w, ENT_QUOTES, 'UTF-8');
            if ($needle !== '') {
                $safe = preg_replace('/'.preg_quote($needle, '/').'(?![^<]*>)/iu', '<mark>$0</mark>', $safe) ?? $safe;
            }
        }

        return $safe;
    }

    /* ── 홈 칸 ── */

    public function homeSections(mixed $list = []): array
    {
        $list = is_array($list) ? array_values($list) : [];
        $items = [];
        try {
            Schema::ensure();
            $svc = new CatalogService();
            foreach (['equipment', 'materials'] as $type) {
                foreach (DB::table(Schema::table($type))->where('status', 'active')->where('brand', '!=', '종류')->orderByRaw("case when image_url is null or image_url = '' then 1 else 0 end")
                    ->orderByDesc('id')->limit($type === 'equipment' ? 8 : 4)->get() as $r) {
                    $c = $svc->card($type, $r);
                    $items[] = ['title' => trim($c['brand'].' '.$c['title']), 'url' => '/catalog/'.$c['key'], 'badge' => $c['kind_label'], 'state' => '', 'excerpt' => implode(' · ', $c['chips']),
                        'chips' => array_slice($c['chips'], 0, 2), 'image' => $c['image'], 'thumbnail' => $c['image'], 'created_at' => $r->created_at, 'view_count' => 0, 'author_name' => '', 'is_new' => false];
                }
            }
        } catch (\Throwable) {
            $items = [];
        }
        $list[] = ['key' => 'catalog', 'title' => '3D 카탈로그', 'subtitle' => '프린터 · 장비 · 필라멘트 · 레진 제원', 'emoji' => '🧊', 'color' => '#4f46e5', 'style' => 'cards', 'more_url' => '/catalog', 'order' => 49, 'items' => $items];

        return $list;
    }

    /* ── 헤더 메뉴 ── */

    public function scripts(mixed $layout = null): mixed
    {
        if (! is_array($layout)) {
            return $layout;
        }
        $layout['scripts'] = is_array($layout['scripts'] ?? null) ? $layout['scripts'] : [];
        foreach ($layout['scripts'] as $s) {
            if (is_array($s) && str_contains((string) ($s['src'] ?? ''), 'catalog-nav.js')) {
                return $layout;
            }
        }
        $layout['scripts'][] = ['src' => '/api/modules/custom-catalog/assets/catalog-nav.js?v='.Catalog::VERSION, 'defer' => true];

        return $layout;
    }
}
