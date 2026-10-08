<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.8 — 「안전 자료 (MSDS)」 칸에 회사 첫 화면 주소(https://bambulab.com 처럼 경로 없음)만 들어 있던 것을 비운다.
 * 누르면 MSDS 가 아니라 홈페이지로 가서 헷갈림. 비워 두면 자동 수집 「안전 자료 찾기」가 진짜 SDS 를 찾아 채움.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::ensure();
        foreach (DB::table(Schema::MATERIALS)->whereNotNull('sds_url')->where('sds_url', '!=', '')->get(['id', 'sds_url']) as $r) {
            if (trim((string) parse_url(trim((string) $r->sds_url), PHP_URL_PATH), '/') === '') {
                DB::table(Schema::MATERIALS)->where('id', $r->id)->update(['sds_url' => null, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
