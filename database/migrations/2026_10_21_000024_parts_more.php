<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\PartsSeed;

/**
 * 0.3.2 🔩 벨트 · 모터 · 메인보드 · 화면 · 팬 · 히터 · 히트베드 · 익스트루더 · 플레이트 (resources/assets/parts-seed-2.json)
 * Bambu Lab 국내 스토어(호환성 칸 · 원화 가격) · Prusa 스토어(호환 표시) · Creality 공식 스토어에서 확인한 것. 0.3.0 의 「Textured PEI Plate」는 국내 스토어 것으로 바꿈.
 */
return new class extends Migration
{
    public function up(): void
    {
        PartsSeed::load(dirname(__DIR__, 2).'/resources/assets/parts-seed-2.json', ['Textured PEI Plate'], 150);
    }

    public function down(): void {}
};
