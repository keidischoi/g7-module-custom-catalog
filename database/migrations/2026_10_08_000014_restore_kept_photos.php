<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 대량 검색 전에 있던 사진만 다시 연결. */
return new class extends Migration
{
    public function up(): void
    {
        $file = dirname(__DIR__, 2).'/resources/assets/photo-map.json';
        if (! is_file($file)) {
            return;
        }
        $map = json_decode((string) file_get_contents($file), true) ?: [];
        foreach (['equipment' => 'cat_equipment', 'materials' => 'cat_materials'] as $kind => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'image_url')) {
                continue;
            }
            foreach ($map[$kind] ?? [] as $key => $name) {
                DB::table($table)->where('key', $key)->update([
                    'image_url' => '/api/modules/custom-catalog/assets/'.$name,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
