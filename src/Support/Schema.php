<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as S;

/**
 * 0.2.0 표 · 칸을 한곳에서 맞춤 — 예전 마이그레이션(000001 ~ 000017)이 어디까지 돌았든, 모자란 것만 더함.
 * 화면 · API 가 처음 쓸 때 한 번 (요청마다 한 번만 확인).
 */
final class Schema
{
    public const EQUIPMENT = 'cat_equipment';

    public const MATERIALS = 'cat_materials';

    public const PHOTOS = 'cat_photos';

    public const SUGGEST = 'cat_suggestions';

    private static bool $done = false;

    public static function table(string $type): string
    {
        return $type === 'materials' ? self::MATERIALS : self::EQUIPMENT;
    }

    public static function reset(): void
    {
        self::$done = false;
    }

    public static function ensure(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;
        if (! S::hasTable(self::EQUIPMENT)) {
            S::create(self::EQUIPMENT, function (Blueprint $t) {
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
        if (! S::hasTable(self::MATERIALS)) {
            S::create(self::MATERIALS, function (Blueprint $t) {
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
        foreach ([self::EQUIPMENT, self::MATERIALS] as $table) {
            $add = [];
            foreach (['image_url' => 'string:300', 'wiki_url' => 'string:300', 'summary' => 'text', 'facts' => 'text', 'specs' => 'text', 'homepage_url' => 'string:500',
                'note' => 'string:500', 'detail' => 'text', 'issues' => 'text', 'memo' => 'text', 'source' => 'string:20', 'ai_photo_at' => 'ts', 'ai_fill_at' => 'ts', 'ai_sds_at' => 'ts'] as $col => $kind) {
                if (! S::hasColumn($table, $col)) {
                    $add[$col] = $kind;
                }
            }
            if ($add) {
                S::table($table, function (Blueprint $t) use ($add) {
                    foreach ($add as $col => $kind) {
                        if ($kind === 'text') {
                            $t->text($col)->nullable();
                        } elseif ($kind === 'ts') {
                            $t->timestamp($col)->nullable();
                        } else {
                            $t->string($col, (int) explode(':', $kind)[1])->nullable();
                        }
                    }
                });
            }
        }
        if (! S::hasTable(self::PHOTOS)) {
            S::create(self::PHOTOS, function (Blueprint $t) {
                $t->id();
                $t->string('item_type', 12)->index();
                $t->string('item_key', 80)->index();
                $t->string('url', 300);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }
        foreach (['source_url' => 500, 'credit' => 200] as $col => $len) {
            if (! S::hasColumn(self::PHOTOS, $col)) {
                S::table(self::PHOTOS, fn (Blueprint $t) => $t->string($col, $len)->nullable());
            }
        }
        if (! S::hasTable(self::SUGGEST)) {
            S::create(self::SUGGEST, function (Blueprint $t) {
                $t->id();
                $t->string('task', 12)->index();          // new · fill · photo
                $t->string('item_type', 12);              // equipment · materials
                $t->string('item_key', 80)->nullable()->index();
                $t->string('title', 160);
                $t->text('payload');
                $t->string('source', 300)->nullable();    // 어느 서버 · 모델 · 어디서
                $t->string('status', 12)->default('pending')->index();   // pending · applied · rejected
                $t->timestamps();
            });
        }
    }

    /** JSON 칸 읽기 @return array<string, mixed> */
    public static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $j = is_string($v) && $v !== '' ? json_decode($v, true) : null;

        return is_array($j) ? $j : [];
    }

    public static function exists(string $type, string $key): bool
    {
        return DB::table(self::table($type))->where('key', $key)->exists();
    }
}
