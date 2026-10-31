<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\ResinSpecs;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.3.27 — Phrozen · Siraya · Formlabs · SUNLU 레진 추가 (빈 칸만 채움)
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
