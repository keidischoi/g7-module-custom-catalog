<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Modules\Custom\Catalog\Support\MulticolorSeed;

/**
 * 0.3.7 🌈 다색 장치 다시 맞추기 — 일부(AMS lite · CFS-C 등)가 주변 장비에 빠졌던 것. 없으면 만들고, 부품으로 남아 있으면 옮기고, 빠진 관련 프린터 연결을 채움.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = MulticolorSeed::sync(dirname(__DIR__, 2).'/resources/assets/parts-seed-4.json', 60);
        if ($r['failed']) {
            Log::warning('[custom-catalog] 다색 장치 맞추기 실패: '.implode(' / ', $r['failed']));
        }
    }

    public function down(): void {}
};
