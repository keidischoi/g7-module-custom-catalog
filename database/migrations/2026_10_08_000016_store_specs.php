<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $file = dirname(__DIR__, 2).'/resources/assets/store-specs.json';
        if (! is_file($file) || ! Schema::hasTable('cat_equipment') || ! Schema::hasColumn('cat_equipment', 'facts')) {
            return;
        }
        $specs = json_decode((string) file_get_contents($file), true) ?: [];
        foreach ($specs as $key => $facts) {
            $row = DB::table('cat_equipment')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $cur = json_decode((string) ($row->facts ?? ''), true) ?: [];
            DB::table('cat_equipment')->where('key', $key)->update([
                'facts' => json_encode(array_merge($cur, $facts), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
    }
};
