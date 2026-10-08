<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_materials') || ! Schema::hasColumn('cat_materials', 'image_url')) {
            return;
        }
        $map = [
            'bambulab-pla-basic' => '/api/modules/custom-catalog/assets/photo-bambulab-pla-basic.jpg',
            'esun-pla-plus' => '/api/modules/custom-catalog/assets/photo-esun-pla-plus.jpg',
            'esun-petg' => '/api/modules/custom-catalog/assets/photo-esun-pla-spool.jpg',
            'esun-abs' => '/api/modules/custom-catalog/assets/photo-esun-pla-spool.jpg',
            'polymaker-polyterra-pla' => '/api/modules/custom-catalog/assets/photo-polymaker-polyterra-pla.jpg',
            'polymaker-polyterra' => '/api/modules/custom-catalog/assets/photo-polymaker-polyterra-pla.jpg',
        ];
        foreach ($map as $key => $url) {
            DB::table('cat_materials')->where('key', $key)->whereNull('image_url')->update(['image_url' => $url, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
