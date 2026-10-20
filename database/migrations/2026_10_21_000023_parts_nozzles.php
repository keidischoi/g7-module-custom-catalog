<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Services\PhotoService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.3.1 🔩 노즐 · 핫엔드 — 제조사 공식 스토어에서 확인한 것 (resources/assets/parts-seed.json).
 *  - 맞는 프린터: 카탈로그에 있으면 잇고, 없으면 그 프린터를 이름만으로 새로 넣고 이음 (Combo 같은 묶음 이름은 넣지 않음)
 *  - 사진: 제품 페이지의 대표 사진을 받아 출처와 함께 (못 받으면 건너뜀 — 나중에 관리자가)
 *  - 0.3.0 이 넣은 Bambu 핫엔드 3개는 더 정확한 것으로 바꿈 (회원 표 · 후기 · 구매처가 없을 때만 지움)
 */
return new class extends Migration
{
    private const OLD = ['Hotend with Hardened Steel Nozzle (X1 · P1 Series)', 'Hotend with Stainless Steel Nozzle (X1 · P1 Series)', 'Hotend (A1 Series)'];

    public function up(): void
    {
        Schema::ensure();
        $file = dirname(__DIR__, 2).'/resources/assets/parts-seed.json';
        $j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($j['items'] ?? null)) {
            return;
        }
        // 0.3.0 처음 부품 중 바뀐 것
        foreach (DB::table(Schema::PARTS)->where('source', 'seed')->whereIn('name', self::OLD)->get() as $o) {
            $used = DB::table(Schema::VOTES)->where('part_key', $o->key)->exists() || DB::table(Schema::REVIEWS)->where('part_key', $o->key)->exists() || DB::table(Schema::LINKS)->where('part_key', $o->key)->exists();
            if (! $used) {
                DB::table(Schema::FITS)->where('part_key', $o->key)->delete();
                DB::table(Schema::PARTS)->where('id', $o->id)->delete();
            }
        }
        $svc = new CatalogService();
        $ph = new PhotoService();
        $eq = DB::table(Schema::EQUIPMENT)->get(['key', 'brand', 'model', 'status']);
        $have = DB::table(Schema::PARTS)->get(['brand', 'name'])->map(static fn ($p) => CatalogService::norm($p->brand).'|'.CatalogService::norm($p->name))->flip();
        $budget = microtime(true) + 240;   // 사진 받기는 4분까지만 (나머지는 관리자가)
        foreach ($j['items'] as $it) {
            if (isset($have[CatalogService::norm($it['brand']).'|'.CatalogService::norm($it['name'])])) {
                continue;
            }
            try {
                $key = $svc->save('parts', ['kind' => $it['kind'], 'brand' => $it['brand'], 'title' => $it['name'], 'summary' => $it['summary'] ?? '', 'homepage_url' => $it['url'] ?? '', 'values' => $it['values'] ?? []], false, 'seed')['key'];
            } catch (\Throwable) {
                continue;
            }
            foreach ($it['fits'] ?? [] as $f) {
                [$b, $m] = $f;
                $pk = $this->printer($eq, (string) $b, (string) $m);
                if ($pk === null && ! preg_match('/combo|^x1$/i', (string) $m)) {
                    try {
                        $pk = $svc->save('equipment', ['kind' => 'fdm', 'brand' => $b, 'title' => $m], false, 'seed')['key'];
                        $eq->push((object) ['key' => $pk, 'brand' => $b, 'model' => $m, 'status' => 'active']);
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
            if (! empty($it['image']) && microtime(true) < $budget) {
                try {
                    $ph->addFromUrl('parts', $key, (string) $it['image'], (string) ($it['credit'] ?? ''), (string) ($it['url'] ?? ''));
                } catch (\Throwable) {
                }
            }
        }
    }

    /** 카탈로그 프린터 찾기 — 제조사가 같고(앞부분) 모델 이름이 같거나 「Original Prusa MK4S」 처럼 끝이 같음 */
    private function printer($eq, string $brand, string $model): ?string
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
            if ($best === null && strlen($nm) >= 2 && str_ends_with($em, $nm) && preg_match('/^(original)?(prusa)?$/', substr($em, 0, -strlen($nm)))) {
                $best = (string) $e->key;
            }
        }

        return $best;
    }

    public function down(): void {}
};
