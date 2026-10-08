<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 검색으로 넣은 엉뚱한 제품 사진을 비운다. 직접 올린 사진(images/)은 유지. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cat_equipment', 'cat_materials'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'image_url')) {
                continue;
            }
            DB::table($table)->where('image_url', 'like', '%/assets/photo-%')->update([
                'image_url' => null,
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('cat_photos')) {
            DB::table('cat_photos')->where('url', 'like', '%/assets/photo-%')->delete();
        }
    }

    public function down(): void
    {
    }
};
