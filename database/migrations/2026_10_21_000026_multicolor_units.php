<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Custom\Catalog\Support\PartsSeed;

/**
 * 0.3.5 🌈 다색 장치 — Bambu AMS · AMS lite · AMS 2 Pro · AMS HT · Hub, Creality CFS · CFS-C · CFS Nano · Co Print, Anycubic ACE Pro · ACE 2 Pro,
 * Elegoo CANVAS, QIDI BOX, Flashforge IFS, Prusa MMU3 · INDX (resources/assets/parts-seed-4.json). 각 공식 스토어의 호환 프린터에 이음.
 */
return new class extends Migration
{
    public function up(): void
    {
        PartsSeed::load(dirname(__DIR__, 2).'/resources/assets/parts-seed-4.json', [], 60);
    }

    public function down(): void {}
};
