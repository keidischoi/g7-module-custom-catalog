<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 0.3.28 레진 프린터 — UVtools 기종 값으로 없는 것만 넣음 (이미 있는 줄은 건드리지 않음) */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_equipment')) {
            return;
        }
        $path = dirname(__DIR__, 2).'/resources/assets/resin-printers.json';
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $norm = fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $have = [];
        foreach (DB::table('cat_equipment')->get(['brand', 'model']) as $r) {
            $have[$norm($r->brand.$r->model)] = true;
        }
        $now = now();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || empty($row['key']) || isset($have[$norm($row['brand'].$row['model'])]) || DB::table('cat_equipment')->where('key', $row['key'])->exists()) {
                continue;
            }
            DB::table('cat_equipment')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $path = dirname(__DIR__, 2).'/resources/assets/resin-printers.json';
        $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (Schema::hasTable('cat_equipment') && is_array($rows)) {
            DB::table('cat_equipment')->whereIn('key', array_column($rows, 'key'))->delete();
        }
    }
};
