<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 공개된 제조사 시작값. 배합은 넣지 않음. 이미 있는 key 는 그대로 둠. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('cat_equipment')) {
            foreach ($this->equipment() as $row) {
                if (! DB::table('cat_equipment')->where('key', $row['key'])->exists()) {
                    DB::table('cat_equipment')->insert($row + ['status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        if (Schema::hasTable('cat_materials')) {
            foreach ($this->materials() as $row) {
                if (! DB::table('cat_materials')->where('key', $row['key'])->exists()) {
                    DB::table('cat_materials')->insert($row + ['status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cat_equipment')) {
            DB::table('cat_equipment')->whereIn('key', array_column($this->equipment(), 'key'))->delete();
        }
        if (Schema::hasTable('cat_materials')) {
            DB::table('cat_materials')->whereIn('key', array_column($this->materials(), 'key'))->delete();
        }
    }

    private function equipment(): array
    {
        return [
            ['key' => 'bambulab-x1c', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'X1 Carbon', 'build_x_mm' => 256, 'build_y_mm' => 256, 'build_z_mm' => 256, 'min_layer_um' => 50, 'multicolor' => true, 'enclosed' => true, 'nozzle' => '0.4 경화강, 최대 300°C', 'homepage_url' => 'https://bambulab.com', 'note' => '256 mm 큐브. 2026년 3월 단종 안내가 있음. 베드 최대 약 110°C.'],
            ['key' => 'bambulab-p1s', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'P1S', 'build_x_mm' => 256, 'build_y_mm' => 256, 'build_z_mm' => 256, 'min_layer_um' => 50, 'multicolor' => true, 'enclosed' => true, 'nozzle' => '0.4, 최대 300°C', 'homepage_url' => 'https://bambulab.com', 'note' => '밀폐. 베드 최대 약 100°C.'],
            ['key' => 'bambulab-a1', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'A1', 'build_x_mm' => 256, 'build_y_mm' => 256, 'build_z_mm' => 256, 'min_layer_um' => 50, 'multicolor' => true, 'enclosed' => false, 'nozzle' => '0.4, 최대 300°C', 'homepage_url' => 'https://bambulab.com', 'note' => '개방형. 베드 최대 약 100°C.'],
            ['key' => 'bambulab-a1-mini', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'model' => 'A1 mini', 'build_x_mm' => 180, 'build_y_mm' => 180, 'build_z_mm' => 180, 'min_layer_um' => 50, 'multicolor' => true, 'enclosed' => false, 'nozzle' => '0.4', 'homepage_url' => 'https://bambulab.com', 'note' => '개방형. 베드 최대 약 80°C.'],
            ['key' => 'prusa-mk4s', 'kind' => 'fdm', 'brand' => 'Prusa', 'model' => 'MK4S', 'build_x_mm' => 250, 'build_y_mm' => 210, 'build_z_mm' => 220, 'min_layer_um' => 50, 'multicolor' => false, 'enclosed' => false, 'nozzle' => '0.4', 'homepage_url' => 'https://www.prusa3d.com', 'note' => '개방형. 다색은 MMU 별도.'],
            ['key' => 'creality-k1c', 'kind' => 'fdm', 'brand' => 'Creality', 'model' => 'K1C', 'build_x_mm' => 220, 'build_y_mm' => 220, 'build_z_mm' => 250, 'min_layer_um' => 100, 'multicolor' => false, 'enclosed' => true, 'nozzle' => '0.4 경화강', 'homepage_url' => 'https://www.creality.com', 'note' => '밀폐. 탄소 섬유 대응 노즐.'],
            ['key' => 'elegoo-saturn-4-ultra', 'kind' => 'sla', 'brand' => 'Elegoo', 'model' => 'Saturn 4 Ultra', 'build_x_mm' => 218, 'build_y_mm' => 122, 'build_z_mm' => 220, 'min_layer_um' => 10, 'multicolor' => false, 'enclosed' => true, 'nozzle' => null, 'homepage_url' => 'https://www.elegoo.com', 'note' => '레진. 405 nm. 세척 · 경화기 별도.'],
            ['key' => 'anycubic-photon-mono-m5s', 'kind' => 'sla', 'brand' => 'Anycubic', 'model' => 'Photon Mono M5s', 'build_x_mm' => 218, 'build_y_mm' => 123, 'build_z_mm' => 200, 'min_layer_um' => 10, 'multicolor' => false, 'enclosed' => true, 'nozzle' => null, 'homepage_url' => 'https://www.anycubic.com', 'note' => '레진. 405 nm.'],
        ];
    }

    private function materials(): array
    {
        return [
            ['key' => 'polymaker-polylite-pla', 'kind' => 'fdm', 'brand' => 'Polymaker', 'name' => 'PolyLite PLA', 'material' => 'PLA', 'material_norm' => 'pla', 'diameter' => 1.75, 'nozzle_min' => 190, 'nozzle_max' => 230, 'bed_min' => 25, 'bed_max' => 60, 'dry_temp' => 55, 'dry_hours' => 6, 'chamber' => 'none', 'weight_g' => 1000, 'traits' => '기본', 'sds_url' => 'https://wiki.polymaker.com/polymaker-products/more-about-our-products/documents/safety-data-sheets', 'storage_note' => '밀봉, 건조제와 함께', 'caution' => '제조사 시작값. 스풀 표기를 우선.'],
            ['key' => 'polymaker-polyterra-pla', 'kind' => 'fdm', 'brand' => 'Polymaker', 'name' => 'PolyTerra PLA', 'material' => 'PLA', 'material_norm' => 'pla', 'diameter' => 1.75, 'nozzle_min' => 190, 'nozzle_max' => 230, 'bed_min' => 25, 'bed_max' => 60, 'dry_temp' => 55, 'dry_hours' => 6, 'chamber' => 'none', 'weight_g' => 1000, 'traits' => '무광', 'sds_url' => 'https://wiki.polymaker.com/polymaker-products/more-about-our-products/documents/safety-data-sheets', 'storage_note' => '밀봉', 'caution' => 'TDS 기준 노즐 190–230°C, 건조 55°C 6시간.'],
            ['key' => 'bambulab-pla-basic', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'name' => 'PLA Basic', 'material' => 'PLA', 'material_norm' => 'pla', 'diameter' => 1.75, 'nozzle_min' => 220, 'nozzle_max' => 230, 'bed_min' => 55, 'bed_max' => 55, 'dry_temp' => 50, 'dry_hours' => 8, 'chamber' => 'none', 'weight_g' => 1000, 'traits' => '기본', 'sds_url' => 'https://bambulab.com', 'storage_note' => '밀봉', 'caution' => '공개 프로파일 시작값. 노즐 220–230°C, 베드 55°C.'],
            ['key' => 'bambulab-petg-hf', 'kind' => 'fdm', 'brand' => 'Bambu Lab', 'name' => 'PETG HF', 'material' => 'PETG', 'material_norm' => 'petg', 'diameter' => 1.75, 'nozzle_min' => 250, 'nozzle_max' => 255, 'bed_min' => 70, 'bed_max' => 70, 'dry_temp' => 65, 'dry_hours' => 8, 'chamber' => 'recommended', 'weight_g' => 1000, 'traits' => '고속', 'sds_url' => 'https://bambulab.com', 'storage_note' => '건조 후 사용', 'caution' => '공개 프로파일 시작값. 노즐 250–255°C, 베드 70°C.'],
            ['key' => 'esun-pla-plus', 'kind' => 'fdm', 'brand' => 'eSUN', 'name' => 'PLA+', 'material' => 'PLA', 'material_norm' => 'pla', 'diameter' => 1.75, 'nozzle_min' => 190, 'nozzle_max' => 220, 'bed_min' => 60, 'bed_max' => 80, 'dry_temp' => 50, 'dry_hours' => 8, 'chamber' => 'none', 'weight_g' => 1000, 'traits' => 'PLA+', 'sds_url' => 'https://www.esun3d.com', 'storage_note' => '밀봉', 'caution' => 'eSUN 안내 시작값. 건조 50°C.'],
            ['key' => 'esun-petg', 'kind' => 'fdm', 'brand' => 'eSUN', 'name' => 'PETG', 'material' => 'PETG', 'material_norm' => 'petg', 'diameter' => 1.75, 'nozzle_min' => 230, 'nozzle_max' => 260, 'bed_min' => 75, 'bed_max' => 90, 'dry_temp' => 60, 'dry_hours' => 8, 'chamber' => 'recommended', 'weight_g' => 1000, 'traits' => null, 'sds_url' => 'https://www.esun3d.com', 'storage_note' => '건조 후 사용', 'caution' => 'eSUN TDS. 건조 60°C 8시간 이상, 노즐 230–260°C.'],
            ['key' => 'esun-abs', 'kind' => 'fdm', 'brand' => 'eSUN', 'name' => 'ABS', 'material' => 'ABS', 'material_norm' => 'abs', 'diameter' => 1.75, 'nozzle_min' => 220, 'nozzle_max' => 260, 'bed_min' => 90, 'bed_max' => 110, 'dry_temp' => 70, 'dry_hours' => 8, 'chamber' => 'required', 'weight_g' => 1000, 'traits' => null, 'sds_url' => 'https://www.esun3d.com', 'storage_note' => '밀폐 보관', 'caution' => '밀폐 권장. 환기. 건조 70–80°C.'],
            ['key' => 'elegoo-standard-resin', 'kind' => 'resin', 'brand' => 'Elegoo', 'name' => 'Standard Photopolymer Resin', 'material' => 'RESIN', 'material_norm' => 'resin', 'diameter' => null, 'nozzle_min' => null, 'nozzle_max' => null, 'bed_min' => null, 'bed_max' => null, 'dry_temp' => null, 'dry_hours' => null, 'chamber' => null, 'weight_g' => 1000, 'traits' => '405nm', 'sds_url' => 'https://www.elegoo.com', 'storage_note' => '직사광선 피해 밀봉', 'caution' => '405 nm. 장갑 · 환기. 세척 · 경화는 제조사 안내를 따름. 배합은 없음.'],
            ['key' => 'anycubic-standard-resin', 'kind' => 'resin', 'brand' => 'Anycubic', 'name' => 'Standard Resin', 'material' => 'RESIN', 'material_norm' => 'resin', 'diameter' => null, 'nozzle_min' => null, 'nozzle_max' => null, 'bed_min' => null, 'bed_max' => null, 'dry_temp' => null, 'dry_hours' => null, 'chamber' => null, 'weight_g' => 1000, 'traits' => '405nm', 'sds_url' => 'https://www.anycubic.com', 'storage_note' => '차광 밀봉', 'caution' => '405 nm. 피부 접촉을 피함. SDS는 제조사 페이지.'],
        ];
    }
};
