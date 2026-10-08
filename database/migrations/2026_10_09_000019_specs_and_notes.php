<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.2
 *  ① 자세한 설명 · 알려진 문제(고질병) · 메모 칸
 *  ② 많이 쓰는 프린터의 속도 · 가속도 · 노즐/베드 온도 · 구조 · 쓸 수 있는 필라멘트, 레진 프린터의 광원 · 파장 · 쓸 수 있는 레진
 *     — 비어 있는 칸만 채움 (이미 적힌 값은 건드리지 않음). 제조사 공개 자료 기준이라 판올림 · 지역에 따라 다를 수 있음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::reset();
        Schema::ensure();
        $file = dirname(__DIR__, 2).'/resources/assets/seed-specs-0.2.2.json';
        $seed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($seed)) {
            return;
        }
        $svc = new CatalogService();
        // 예전 글에서 옮긴 재료 목록에 「ASA. 가능: PA」 처럼 붙어 있던 것을 하나씩으로
        foreach (DB::table(Schema::EQUIPMENT)->whereNotNull('materials')->get(['id', 'materials']) as $r) {
            $list = Schema::json($r->materials);
            $out = [];
            foreach ($list as $m) {
                foreach (preg_split('/\s*(?:가능\s*:|권장\s*:|[.;])\s*/u', (string) $m) ?: [] as $x) {
                    $x = trim($x);
                    if ($x !== '' && ! in_array($x, $out, true)) {
                        $out[] = $x;
                    }
                }
            }
            if ($out !== $list) {
                DB::table(Schema::EQUIPMENT)->where('id', $r->id)->update(['materials' => $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null]);
            }
        }
        $rows = DB::table(Schema::EQUIPMENT)->where('status', 'active')->get(['key', 'kind', 'brand', 'model']);
        foreach ($seed['items'] ?? [] as $it) {
            foreach ($rows as $r) {
                if ($r->kind === $it['kind'] && CatalogService::norm($r->brand) === CatalogService::norm($it['brand']) && CatalogService::norm($r->model) === CatalogService::norm($it['model'])) {
                    try {
                        $svc->save('equipment', ['key' => (string) $r->key, 'values' => $it['values']], true);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        $brands = array_map([CatalogService::class, 'norm'], $seed['resin']['brands'] ?? []);
        foreach ($rows as $r) {
            if ($r->kind === 'dlp' && in_array(CatalogService::norm($r->brand), $brands, true)) {
                try {
                    $svc->save('equipment', ['key' => (string) $r->key, 'values' => $seed['resin']['values']], true);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function down(): void {}
};
