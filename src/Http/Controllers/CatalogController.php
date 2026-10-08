<?php

namespace Modules\Custom\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Custom\Catalog\Api\Catalog;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\Collector;
use Modules\Custom\Catalog\Services\PhotoService;
use Modules\Custom\Catalog\Support\Access;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Settings;

/**
 * 0.2.0 누구나 보는 쪽
 *
 *  GET meta                 → 탭 · 종류 · 칸 정의 · 탭마다 개수 · 내가 편집할 수 있는지
 *  GET items?tab=&brand=&q= → 목록
 *  GET items/{key}          → 상세
 *  GET book                 → 다른 모듈(업체검색)이 뽑아 가는 목록
 *  GET images/{file}        → 올린 사진
 */
class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    private static function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function meta(Request $r): JsonResponse
    {
        $s = Settings::all();
        $meta = Fields::meta();
        $meta['tabs'] = array_values(array_filter($meta['tabs'], static fn ($t) => ! in_array($t['key'], $s['tabs_off'], true)));
        $this->autoCollect();

        return self::ok($meta + ['counts' => $this->catalog->counts(), 'per_page' => $s['per_page'], 'can_edit' => Access::canEdit($r),
            'logos' => \Modules\Custom\Catalog\Support\Logos::map()]);
    }

    public function items(Request $r): JsonResponse
    {
        $tab = (string) $r->query('tab', '');
        $def = null;
        foreach (Fields::TABS as $t) {
            if ($t[0] === $tab) {
                $def = $t;
            }
        }
        $type = $def ? $def[3] : ($r->query('type') === 'materials' ? 'materials' : 'equipment');
        $kinds = $def ? $def[4] : array_filter(explode(',', (string) $r->query('kind', '')));
        $edit = Access::canEdit($r);

        return self::ok($this->catalog->list([
            'type' => $type, 'kinds' => $kinds, 'brand' => (string) $r->query('brand', ''), 'q' => mb_substr((string) $r->query('q', ''), 0, 60),
            'sort' => (string) $r->query('sort', ''), 'page' => (int) $r->query('page', 1), 'per' => (int) $r->query('per', Settings::get('per_page')),
            'flags' => array_filter(explode(',', (string) $r->query('flags', ''))),
            'status' => $edit ? (string) $r->query('status', 'active') : 'active',
        ]));
    }

    public function item(Request $r, string $key): JsonResponse
    {
        $edit = Access::canEdit($r);
        $hit = $this->catalog->find($key, null, $edit);
        if (! $hit) {
            return response()->json(['success' => false, 'message' => '없는 항목이에요.'], 404);
        }

        return self::ok($this->catalog->detail($hit[0], $hit[1], $edit));
    }

    /** 업체검색 등 다른 모듈용 목록 (사진은 주지 않음 — 그쪽 사진을 그대로 씀) */
    public function book(Request $r): JsonResponse
    {
        return self::ok($r->query('type') === 'materials' ? Catalog::materialBook() : Catalog::equipmentBook());
    }

    public function image(string $file)
    {
        $body = PhotoService::read($file);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=2592000']);
    }

    /** 스케줄이 돌지 않는 서버를 위해 — 화면을 열 때 응답을 보낸 뒤 조용하면 한 번 */
    private function autoCollect(): void
    {
        try {
            if (! Settings::get('auto') || ! function_exists('app')) {
                return;
            }
            $c = app(Collector::class);
            if (! $c->due() || ! $c->quiet()['ok']) {
                return;
            }
            app()->terminating(static function () use ($c) {
                try {
                    @set_time_limit(300);
                    $c->tick();
                } catch (\Throwable) {
                }
            });
        } catch (\Throwable) {
        }
    }
}
