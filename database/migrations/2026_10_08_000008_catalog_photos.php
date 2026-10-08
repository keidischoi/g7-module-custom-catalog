<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 확인된 제품 사진. 긴 변 720px, 장당 80KB 이하. 없는 키는 건드리지 않음. */
return new class extends Migration
{
    public function up(): void
    {
        $maps = [
            'cat_equipment' => [
            'anycubic-kobra-3-combo-fdm' => '/api/modules/custom-catalog/assets/photo-anycubic-kobra-3-fdm.jpg',
            'anycubic-kobra-3-fdm' => '/api/modules/custom-catalog/assets/photo-anycubic-kobra-3-fdm.jpg',
            'anycubic-kobra-3-max-combo-fdm' => '/api/modules/custom-catalog/assets/photo-anycubic-kobra-3-max.jpg',
            'anycubic-kobra-3-max-fdm' => '/api/modules/custom-catalog/assets/photo-anycubic-kobra-3-max.jpg',
            'bambu-lab-a1-fdm' => '/api/modules/custom-catalog/assets/photo-bambu-lab-a1-fdm.jpg',
            'bambu-lab-p1s-fdm' => '/api/modules/custom-catalog/assets/photo-bambulab-p1s.jpg',
            'bambu-lab-x1-carbon-fdm' => '/api/modules/custom-catalog/assets/photo-bambulab-x1c.jpg',
            'bambulab-a1' => '/api/modules/custom-catalog/assets/photo-bambu-lab-a1-fdm.jpg',
            'bambulab-p1s' => '/api/modules/custom-catalog/assets/photo-bambulab-p1s.jpg',
            'bambulab-x1c' => '/api/modules/custom-catalog/assets/photo-bambulab-x1c.jpg',
            'creality-k1-fdm' => '/api/modules/custom-catalog/assets/photo-creality-k1-fdm.jpg',
            'creality-k1-max-fdm' => '/api/modules/custom-catalog/assets/photo-creality-k1-max-fdm.jpg',
            'creality-k1c' => '/api/modules/custom-catalog/assets/photo-creality-k1c-fdm.jpg',
            'creality-k1c-fdm' => '/api/modules/custom-catalog/assets/photo-creality-k1c-fdm.jpg',
            'creality-k2-plus-fdm' => '/api/modules/custom-catalog/assets/photo-creality-k2-plus-fdm.jpg',
            'elegoo-centauri-carbon-fdm' => '/api/modules/custom-catalog/assets/photo-elegoo-centauri-carbon-fdm.jpg',
            'elegoo-neptune-4-fdm' => '/api/modules/custom-catalog/assets/photo-elegoo-neptune-4-pro-fdm.jpg',
            'elegoo-neptune-4-pro-fdm' => '/api/modules/custom-catalog/assets/photo-elegoo-neptune-4-pro-fdm.jpg',
            'flashforge-adventurer-5m-fdm' => '/api/modules/custom-catalog/assets/photo-flashforge-adventurer-5m-fdm.jpg',
            'flashforge-adventurer-5m-pro-fdm' => '/api/modules/custom-catalog/assets/photo-flashforge-adventurer-5m-fdm.jpg',
            'prusa-mk4s' => '/api/modules/custom-catalog/assets/photo-prusa-research-original-prusa-mk4s-fdm.jpg',
            'prusa-research-original-prusa-mk4s-fdm' => '/api/modules/custom-catalog/assets/photo-prusa-research-original-prusa-mk4s-fdm.jpg',
            'prusa-research-original-prusa-xl-fdm' => '/api/modules/custom-catalog/assets/photo-prusa-research-original-prusa-xl-fdm.jpg',
            'prusa-research-prusa-core-one-fdm' => '/api/modules/custom-catalog/assets/photo-prusa-research-prusa-core-one-fdm.jpg',
            'xtool-f1-ultra-laser' => '/api/modules/custom-catalog/assets/photo-xtool-f1-ultra-laser.jpg',
            'xtool-p2s-laser' => '/api/modules/custom-catalog/assets/photo-xtool-p2s-laser.jpg',
            'xtool-s1-laser' => '/api/modules/custom-catalog/assets/photo-xtool-s1-laser.jpg'
            ],
            'cat_materials' => [
            'esun-pla-plus' => '/api/modules/custom-catalog/assets/photo-esun-pla-plus.jpg',
            'polymaker-polyterra' => '/api/modules/custom-catalog/assets/photo-polymaker-polyterra-pla.jpg',
            'polymaker-polyterra-pla' => '/api/modules/custom-catalog/assets/photo-polymaker-polyterra-pla.jpg'
            ],
        ];
        foreach ($maps as $table => $rows) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'image_url')) {
                continue;
            }
            foreach ($rows as $key => $url) {
                DB::table($table)->where('key', $key)->update(['image_url' => $url, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
