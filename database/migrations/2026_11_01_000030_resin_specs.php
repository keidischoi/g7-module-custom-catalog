<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\ResinSpecs;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.3.24 — 레진 공식 제원 · 프린터별 노출 프로필 (빈 칸만 채움)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensure();
        ResinSpecs::apply();
    }

    public function down(): void
    {
    }
};
