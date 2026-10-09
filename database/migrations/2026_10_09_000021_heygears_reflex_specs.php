<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.12 — HeyGears UltraCraft Reflex 제원 (관리자가 확인한 값 — 예전 값 위에 덮어씀). 항목이 없으면 새로 넣음.
 */
return new class extends Migration
{
    public const VALUES = [
        'released_on' => '2023-06',
        'sale' => '판매 중',
        'origin' => '중국',
        'build_x_mm' => 192, 'build_y_mm' => 121, 'build_z_mm' => 220,
        'min_layer_um' => 20,
        'xy_um' => 33,
        'print_speed_h' => '평균 27 mm/h (층 50 µm 기준)',
        'light' => 'LCD (MSLA)',
        'lcd_res' => '6K Mono (5760×3600)',
        'vat_heat' => true,
        'auto_feed' => true,
        'camera' => true,
        'ai_detect' => true,
        'air_filter' => true,
        'connect' => ['USB', 'Wi-Fi', 'LAN'],
        'open_source' => false,
        'slicer' => 'Blueprint Studio (HeyGears 전용 슬라이서)',
        'size_w' => 400, 'size_d' => 420, 'size_h' => 572,
        'weight_kg' => 25,
        'power_w' => 350,
        'voltage' => '100–240 V AC, 50/60 Hz',
    ];

    public function up(): void
    {
        Schema::ensure();
        $key = null;
        foreach (DB::table(Schema::EQUIPMENT)->whereIn('kind', ['dlp', 'sla'])->get(['key', 'brand', 'model']) as $r) {
            if (CatalogService::norm($r->brand) === CatalogService::norm('HeyGears') && CatalogService::norm($r->model) === CatalogService::norm('UltraCraft Reflex')) {
                $key = (string) $r->key;
                break;
            }
        }
        try {
            (new CatalogService())->save('equipment', $key !== null ? ['key' => $key, 'values' => self::VALUES]
                : ['kind' => 'dlp', 'brand' => 'HeyGears', 'title' => 'UltraCraft Reflex', 'values' => self::VALUES]);
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
    }
};
