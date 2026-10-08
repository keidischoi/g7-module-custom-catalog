<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 인터넷에서 모은 제품 실사진. 업체 보관 사진과 별개. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (["cat_equipment", "cat_materials"] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, "image_url")) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string("image_url", 200)->nullable();
                });
            }
        }
        $photos = [
            "cat_equipment" => ["bambulab-x1c" => "/api/modules/custom-catalog/assets/photo-bambulab-x1c.jpg"],
            "cat_materials" => [
                "polymaker-polyterra-pla" => "/api/modules/custom-catalog/assets/photo-polymaker-polyterra-pla.jpg",
                "esun-pla-plus" => "/api/modules/custom-catalog/assets/photo-esun-pla-plus.jpg",
            ],
        ];
        foreach ($photos as $table => $map) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($map as $key => $url) {
                DB::table($table)->where("key", $key)->update(["image_url" => $url, "updated_at" => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (["cat_equipment", "cat_materials"] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, "image_url")) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn("image_url");
                });
            }
        }
    }
};
