<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 업체검색 Catalog 재료 종류를 카탈로그 재료로. 이미 있는 key 는 유지. */
return new class extends Migration
{
    public function up(): void
    {
        $file = dirname(__DIR__, 2).'/resources/assets/material-types.json';
        if (! is_file($file) || ! Schema::hasTable('cat_materials')) {
            return;
        }
        $rows = json_decode((string) file_get_contents($file), true) ?: [];
        foreach ($rows as $row) {
            if (DB::table('cat_materials')->where('key', $row['key'])->exists()) {
                continue;
            }
            DB::table('cat_materials')->insert([
                'key' => $row['key'],
                'kind' => $row['kind'],
                'brand' => $row['brand'],
                'name' => $row['name'],
                'material' => $row['material'],
                'material_norm' => mb_strtolower($row['material']),
                'note' => $row['note'],
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
    }
};
