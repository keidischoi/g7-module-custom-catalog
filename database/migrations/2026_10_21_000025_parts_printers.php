<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\PartsSeed;
use Modules\Custom\Catalog\Support\PrinterSeed;

/**
 * 0.3.4
 *  ① 🔩 Anycubic · Elegoo · QIDI · Flashforge 공식 스토어 부품 (resources/assets/parts-seed-3.json) — 레진 프린터 부품(FEP · LCD · 판)도
 *  ② 🖨️ 프린터 기본 제원 · 대표 사진 (resources/assets/printers-seed.json) — 출력 크기 · 구조 · 노즐(OrcaSlicer 설정), 밀폐 · 다색(제조사 자료), 사진(공식 스토어)
 *  ③ 0.2.2 의 제원(속도 · 온도 · 재료)을 그 뒤에 생긴 프린터 항목에도 — 비어 있는 칸만
 */
return new class extends Migration
{
    public function up(): void
    {
        $dir = dirname(__DIR__, 2).'/resources/assets';
        PartsSeed::load($dir.'/parts-seed-3.json', [], 90);
        PrinterSeed::load($dir.'/printers-seed.json', 90);
        PrinterSeed::load($dir.'/seed-specs-0.2.2.json', 0);
    }

    public function down(): void {}
};
