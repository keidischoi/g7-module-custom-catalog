<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\MulticolorSeed;

/**
 * 0.3.8 AMS Hub · AMS Hub(A1) · SPARKX i7 CFS Nano Kit · IFS 부품 · Co Print 다색 업그레이드 키트 · CANVAS for Centauri Carbon 은 다색 「장치」가 아니라 부속품 — 주변 장비 → 부품(🌈 다색 장치 · 스풀)으로 되돌림.
 * 관련 프린터 · 표 · 후기 · 구매처 · 사진은 그대로 따라감.
 */
return new class extends Migration
{
    public function up(): void
    {
        MulticolorSeed::accessories(dirname(__DIR__, 2).'/resources/assets/parts-seed-4.json');
    }

    public function down(): void {}
};
