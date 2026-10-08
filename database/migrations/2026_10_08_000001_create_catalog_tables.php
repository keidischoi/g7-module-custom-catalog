<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3D 카탈로그 — 자료의 주인.
 * 업체검색 cmp_equipment / cmp_spools 는 보유 · 재고만 두고 catalog_key 로 이 표를 가리킨다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_equipment')) {
            Schema::create('cat_equipment', function (Blueprint $t) {
                $t->id();
                $t->string('key', 60)->unique();
                $t->string('kind', 16)->index();
                $t->string('brand', 60);
                $t->string('model', 80);
                $t->unsignedSmallInteger('build_x_mm')->nullable();
                $t->unsignedSmallInteger('build_y_mm')->nullable();
                $t->unsignedSmallInteger('build_z_mm')->nullable();
                $t->unsignedSmallInteger('min_layer_um')->nullable();
                $t->boolean('multicolor')->default(false);
                $t->boolean('enclosed')->nullable();
                $t->string('nozzle', 120)->nullable();
                $t->json('materials')->nullable();
                $t->date('released_on')->nullable();
                $t->string('homepage_url', 500)->nullable();
                $t->string('note', 500)->nullable();
                $t->string('status', 12)->default('active')->index();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cat_materials')) {
            Schema::create('cat_materials', function (Blueprint $t) {
                $t->id();
                $t->string('key', 80)->unique();
                $t->string('kind', 12)->default('fdm')->index();
                $t->string('brand', 60);
                $t->string('name', 80);
                $t->string('material', 40);
                $t->string('material_norm', 40)->default('')->index();
                $t->string('color', 30)->nullable();
                $t->string('color_hex', 120)->nullable();
                $t->decimal('diameter', 4, 2)->nullable();
                $t->unsignedSmallInteger('nozzle_min')->nullable();
                $t->unsignedSmallInteger('nozzle_max')->nullable();
                $t->unsignedSmallInteger('bed_min')->nullable();
                $t->unsignedSmallInteger('bed_max')->nullable();
                $t->unsignedSmallInteger('dry_temp')->nullable();
                $t->unsignedTinyInteger('dry_hours')->nullable();
                $t->string('chamber', 12)->nullable();
                $t->unsignedInteger('weight_g')->nullable();
                $t->string('traits', 200)->nullable();
                $t->string('sds_url', 500)->nullable();
                $t->string('storage_note', 300)->nullable();
                $t->string('caution', 300)->nullable();
                $t->string('status', 12)->default('active')->index();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cat_barcodes')) {
            Schema::create('cat_barcodes', function (Blueprint $t) {
                $t->id();
                $t->string('barcode', 40)->unique();
                $t->string('material_key', 80)->index();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_barcodes');
        Schema::dropIfExists('cat_materials');
        Schema::dropIfExists('cat_equipment');
    }
};
