<?php

namespace Modules\Custom\Catalog\Api;

use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.0 다른 모듈이 카탈로그 목록을 뽑아 가는 곳 (업체검색 장비 · 재고 등록 화면의 고르기 목록).
 *
 *   if (class_exists(\Modules\Custom\Catalog\Api\Catalog::class)) { $book = \Modules\Custom\Catalog\Api\Catalog::equipmentBook(); }
 *   HTTP: GET /api/modules/custom-catalog/book  ·  /book?type=materials
 *
 * 목록만 줌 — 사진은 주지 않음 (업체검색은 제 사진 · 제 규칙을 그대로 씀).
 * equipmentBook 의 꼴은 업체검색 equipment-catalog.json 과 같음: { 종류: [ {b: 제조사, m: 모델, s: [가로, 세로, 높이], mc: 1(다색), key} ] }
 */
final class Catalog
{
    public const VERSION = '0.2.0';

    /** @return array<string, list<array<string, mixed>>> */
    public static function equipmentBook(): array
    {
        $out = [];
        try {
            Schema::ensure();
            foreach (DB::table(Schema::EQUIPMENT)->where('status', 'active')->orderBy('brand')->orderBy('model')->get(['key', 'kind', 'brand', 'model', 'build_x_mm', 'build_y_mm', 'build_z_mm', 'multicolor', 'enclosed']) as $r) {
                if (! Fields::validKind('equipment', (string) $r->kind) || trim((string) $r->model) === '') {
                    continue;
                }
                $row = ['b' => (string) $r->brand, 'm' => (string) $r->model, 'key' => (string) $r->key];
                if ($r->build_x_mm && $r->build_y_mm) {
                    $row['s'] = [(int) $r->build_x_mm, (int) $r->build_y_mm, (int) ($r->build_z_mm ?? 0)];
                }
                if (! empty($r->multicolor)) {
                    $row['mc'] = 1;
                }
                if ($r->enclosed !== null) {
                    $row['en'] = (int) (bool) $r->enclosed;
                }
                $out[(string) $r->kind][] = $row;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * 재료 제품 — { 종류(fdm|resin|powder): [ {b, n: 제품 이름, mat: 재료, d: 직경, nz: [최저, 최고], bed: [최저, 최고], dry: [℃, 시간], ch: 챔버, w: 용량 g, key} ] }
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function materialBook(): array
    {
        $out = [];
        try {
            Schema::ensure();
            foreach (DB::table(Schema::MATERIALS)->where('status', 'active')->orderBy('brand')->orderBy('name')->get() as $r) {
                if (! Fields::validKind('materials', (string) $r->kind) || in_array($r->brand, ['종류'], true)) {
                    continue;
                }
                $row = ['b' => (string) $r->brand, 'n' => (string) $r->name, 'mat' => (string) $r->material, 'key' => (string) $r->key];
                if ($r->diameter) {
                    $row['d'] = (float) $r->diameter;
                }
                if ($r->nozzle_min && $r->nozzle_max) {
                    $row['nz'] = [(int) $r->nozzle_min, (int) $r->nozzle_max];
                }
                if ($r->bed_min && $r->bed_max) {
                    $row['bed'] = [(int) $r->bed_min, (int) $r->bed_max];
                }
                if ($r->dry_temp) {
                    $row['dry'] = [(int) $r->dry_temp, (int) ($r->dry_hours ?? 0)];
                }
                if (trim((string) $r->chamber) !== '') {
                    $row['ch'] = (string) $r->chamber;
                }
                if ($r->weight_g) {
                    $row['w'] = (int) $r->weight_g;
                }
                $out[(string) $r->kind][] = $row;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /** 제조사 목록 @return list<string> */
    public static function brands(string $type = 'equipment'): array
    {
        try {
            Schema::ensure();

            return DB::table(Schema::table($type))->where('status', 'active')->whereNotIn('brand', ['종류'])->distinct()->orderBy('brand')->pluck('brand')->map('strval')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
