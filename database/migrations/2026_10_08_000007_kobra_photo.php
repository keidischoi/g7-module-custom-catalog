<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_equipment') || ! Schema::hasColumn('cat_equipment', 'image_url')) {
            return;
        }
        DB::table('cat_equipment')->where('key', 'anycubic-kobra-3-max-combo-fdm')->update([
            'image_url' => '/api/modules/custom-catalog/assets/photo-anycubic-kobra-3-max.jpg',
        ]);
    }

    public function down(): void
    {
    }
};
