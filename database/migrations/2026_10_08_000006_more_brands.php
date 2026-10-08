<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 업체 장비 53개 제조사와 대표 필라멘트 제조사. 이미 있는 key 는 유지. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('cat_equipment')) {
            $path = dirname(__DIR__, 2).'/resources/assets/companies-equipment.json';
            $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
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
        if (! Schema::hasTable('cat_materials')) {
            return;
        }
        $materials = [
            ['polymaker-polyterra', 'fdm', 'Polymaker', 'PolyTerra PLA', 'PLA'],
            ['esun-pla-plus', 'fdm', 'eSUN', 'PLA+', 'PLA'],
            ['bambulab-pla-basic', 'fdm', 'Bambu Lab', 'PLA Basic', 'PLA'],
            ['sunlu-pla', 'fdm', 'SUNLU', 'PLA', 'PLA'],
            ['overture-pla', 'fdm', 'Overture', 'PLA', 'PLA'],
            ['hatchbox-pla', 'fdm', 'Hatchbox', 'PLA', 'PLA'],
            ['prusament-pla', 'fdm', 'Prusament', 'PLA', 'PLA'],
            ['colorfabb-pla', 'fdm', 'colorFabb', 'PLA/PHA', 'PLA'],
            ['fillamentum-pla', 'fdm', 'Fillamentum', 'PLA Extrafill', 'PLA'],
            ['fiberlogy-pla', 'fdm', 'Fiberlogy', 'Easy PLA', 'PLA'],
            ['3dxtech-pla', 'fdm', '3DXTECH', 'PLA', 'PLA'],
            ['eryone-pla', 'fdm', 'ERYONE', 'PLA', 'PLA'],
            ['amolen-pla', 'fdm', 'AMOLEN', 'PLA', 'PLA'],
            ['creality-hyper-pla', 'fdm', 'Creality', 'Hyper PLA', 'PLA'],
            ['flashforge-pla', 'fdm', 'Flashforge', 'PLA', 'PLA'],
            ['qidi-pla', 'fdm', 'QIDI', 'PLA Rapido', 'PLA'],
            ['raise3d-pla', 'fdm', 'Raise3D', 'Premium PLA', 'PLA'],
            ['ultimaker-pla', 'fdm', 'UltiMaker', 'PLA', 'PLA'],
            ['basf-ultrafuse-pla', 'fdm', 'BASF Ultrafuse', 'PLA', 'PLA'],
            ['spectrum-pla', 'fdm', 'Spectrum', 'PLA Premium', 'PLA'],
            ['polymaker-petg', 'fdm', 'Polymaker', 'PolyLite PETG', 'PETG'],
            ['esun-petg', 'fdm', 'eSUN', 'PETG', 'PETG'],
            ['sunlu-petg', 'fdm', 'SUNLU', 'PETG', 'PETG'],
            ['esun-abs', 'fdm', 'eSUN', 'ABS+', 'ABS'],
            ['polymaker-abs', 'fdm', 'Polymaker', 'PolyLite ABS', 'ABS'],
            ['esun-tpu', 'fdm', 'eSUN', 'eTPU-95A', 'TPU'],
            ['ninjatek-tpu', 'fdm', 'NinjaTek', 'NinjaFlex', 'TPU'],
            ['elegoo-resin', 'resin', 'Elegoo', 'Standard Resin', 'Resin'],
            ['anycubic-resin', 'resin', 'Anycubic', 'Standard Resin', 'Resin'],
            ['phrozen-resin', 'resin', 'Phrozen', 'Aqua-Gray 8K', 'Resin'],
            ['siraya-blu', 'resin', 'Siraya Tech', 'Blu', 'Resin'],
            ['formlabs-clear', 'resin', 'Formlabs', 'Clear', 'Resin'],
        ];
        foreach ($materials as [$key, $kind, $brand, $name, $material]) {
            if (DB::table('cat_materials')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('cat_materials')->insert([
                'key' => $key, 'kind' => $kind, 'brand' => $brand, 'name' => $name,
                'material' => $material, 'material_norm' => $material, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
    }
};
