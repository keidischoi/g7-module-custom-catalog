<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.3.0 🔩 부품 · 업그레이드 — 표를 만들고 처음 보일 부품 몇 개 (제조사가 공개한 호환 정보가 분명한 것만).
 * 맞는 프린터는 카탈로그에 그 프린터가 있을 때만 잇고, 나머지는 회원 · 관리자가 채움. 이미 있으면 건너뜀.
 */
return new class extends Migration
{
    /** [종류, 제조사, 이름, 값, 맞는 프린터(제조사, 모델)들] */
    private const SEED = [
        ['hotend', 'Bambu Lab', 'Hotend with Hardened Steel Nozzle (X1 · P1 Series)', ['genuine' => '정품', 'nozzle_size' => ['0.4', '0.6', '0.8'], 'nozzle_material' => '경화강', 'abrasive_ok' => true, 'install' => '그대로 끼움'],
            [['Bambu Lab', 'X1 Carbon'], ['Bambu Lab', 'X1E'], ['Bambu Lab', 'P1S'], ['Bambu Lab', 'P1P']]],
        ['hotend', 'Bambu Lab', 'Hotend with Stainless Steel Nozzle (X1 · P1 Series)', ['genuine' => '정품', 'nozzle_size' => ['0.2', '0.4'], 'nozzle_material' => '스테인리스', 'install' => '그대로 끼움'],
            [['Bambu Lab', 'X1 Carbon'], ['Bambu Lab', 'X1E'], ['Bambu Lab', 'P1S'], ['Bambu Lab', 'P1P']]],
        ['hotend', 'Bambu Lab', 'Hotend (A1 Series)', ['genuine' => '정품', 'nozzle_size' => ['0.2', '0.4', '0.6', '0.8'], 'install' => '그대로 끼움'],
            [['Bambu Lab', 'A1'], ['Bambu Lab', 'A1 mini']]],
        ['bed', 'Bambu Lab', 'Textured PEI Plate', ['genuine' => '정품', 'bed_surface' => '텍스처 PEI', 'magnetic' => true, 'bed_size' => '257 × 257 mm', 'install' => '그대로 끼움'],
            [['Bambu Lab', 'X1 Carbon'], ['Bambu Lab', 'X1E'], ['Bambu Lab', 'P1S'], ['Bambu Lab', 'P1P'], ['Bambu Lab', 'A1']]],
    ];

    public function up(): void
    {
        Schema::ensure();
        $svc = new CatalogService();
        $eq = DB::table(Schema::EQUIPMENT)->where('status', 'active')->get(['key', 'brand', 'model']);
        foreach (self::SEED as [$kind, $brand, $name, $values, $printers]) {
            $exists = DB::table(Schema::PARTS)->get(['brand', 'name'])->first(static fn ($p) => CatalogService::norm($p->brand) === CatalogService::norm($brand) && CatalogService::norm($p->name) === CatalogService::norm($name));
            if ($exists) {
                continue;
            }
            $key = $svc->save('parts', ['kind' => $kind, 'brand' => $brand, 'title' => $name, 'values' => $values], false, 'seed')['key'];
            foreach ($printers as [$pb, $pm]) {
                $hit = $eq->first(static fn ($e) => CatalogService::norm($e->brand) === CatalogService::norm($pb) && CatalogService::norm($e->model) === CatalogService::norm($pm));
                if ($hit) {
                    DB::table(Schema::FITS)->insertOrIgnore(['part_key' => $key, 'printer_key' => (string) $hit->key, 'approved' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void {}
};
