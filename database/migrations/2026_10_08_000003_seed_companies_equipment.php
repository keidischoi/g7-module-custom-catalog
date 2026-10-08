<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 업체검색 equipment-catalog.json 에 있던 기종. 이미 있는 key 는 유지. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_equipment')) {
            return;
        }
        $path = dirname(__DIR__, 2).'/resources/assets/companies-equipment.json';
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $now = now();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || empty($row['key']) || DB::table('cat_equipment')->where('key', $row['key'])->exists()) {
                continue;
            }
            DB::table('cat_equipment')->insert([
                'key' => $row['key'],
                'kind' => $row['kind'] ?? 'other',
                'brand' => $row['brand'] ?? '',
                'model' => $row['model'] ?? '',
                'build_x_mm' => $row['build_x_mm'] ?? null,
                'build_y_mm' => $row['build_y_mm'] ?? null,
                'build_z_mm' => $row['build_z_mm'] ?? null,
                'multicolor' => ! empty($row['multicolor']),
                'note' => $row['note'] ?? null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cat_equipment')) {
            return;
        }
        $path = dirname(__DIR__, 2).'/resources/assets/companies-equipment.json';
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $keys = array_column(is_array($rows) ? $rows : [], 'key');
        if ($keys) {
            DB::table('cat_equipment')->whereIn('key', $keys)->delete();
        }
    }
};
