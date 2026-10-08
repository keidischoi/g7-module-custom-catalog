<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 사진 여러 장, 위키 기준 설명. 이미 있는 칸은 유지. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cat_photos')) {
            Schema::create('cat_photos', function (Blueprint $t) {
                $t->id();
                $t->string('item_type', 12)->index();
                $t->string('item_key', 80)->index();
                $t->string('url', 200);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }
        foreach (['cat_equipment', 'cat_materials'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'wiki_url')) {
                    $t->string('wiki_url', 300)->nullable();
                }
                if (! Schema::hasColumn($table, 'summary')) {
                    $t->text('summary')->nullable();
                }
                if (! Schema::hasColumn($table, 'facts')) {
                    $t->json('facts')->nullable();
                }
            });
        }
        $wiki = [
            'bambulab-x1c' => ['https://en.wikipedia.org/wiki/Bambu_Lab', 'Bambu Lab은 중국 선전에 본사를 둔 데스크톱 FDM 프린터 회사입니다. DJI 출신 엔지니어들이 설립했고, 상하이와 미국 오스틴에도 거점이 있습니다.', ['방식' => 'FDM, 밀폐', '출력 크기' => '256 × 256 × 256 mm', '노즐' => '0.4 mm 경화강, 최대 300°C', '베드' => '최대 약 110°C', '다색' => 'AMS 지원', '비고' => '2026년 3월 단종 안내가 있는 모델']],
            'bambulab-p1s' => ['https://en.wikipedia.org/wiki/Bambu_Lab', 'Bambu Lab P1S는 같은 회사의 밀폐형 FDM 프린터입니다.', ['방식' => 'FDM, 밀폐', '출력 크기' => '256 × 256 × 256 mm', '노즐' => '0.4 mm, 최대 300°C', '베드' => '최대 약 100°C', '다색' => 'AMS 지원']],
            'bambulab-a1' => ['https://en.wikipedia.org/wiki/Bambu_Lab', 'Bambu Lab A1은 개방형 베드슬링거 FDM 프린터입니다.', ['방식' => 'FDM, 개방형', '출력 크기' => '256 × 256 × 256 mm', '노즐' => '0.4 mm, 최대 300°C', '베드' => '최대 약 100°C', '다색' => 'AMS lite 지원']],
            'bambulab-a1-mini' => ['https://en.wikipedia.org/wiki/Bambu_Lab', 'Bambu Lab A1 mini는 작은 개방형 FDM 프린터입니다.', ['방식' => 'FDM, 개방형', '출력 크기' => '180 × 180 × 180 mm', '노즐' => '0.4 mm', '베드' => '최대 약 80°C']],
            'prusa-mk4s' => ['https://en.wikipedia.org/wiki/Prusa_i3', 'Original Prusa는 Prusa i3 계열의 오픈소스 FDM 프린터입니다. MK4S는 그 후속 모델입니다.', ['방식' => 'FDM, 개방형', '출력 크기' => '250 × 210 × 220 mm', '노즐' => '0.4 mm', '다색' => 'MMU는 별도', '제조' => 'Prusa Research, 체코']],
            'creality-k1c' => ['https://en.wikipedia.org/wiki/Creality', 'Creality는 중국의 데스크톱 3D 프린터 제조사입니다. K1C는 밀폐형 고속 FDM이고 탄소섬유 필라멘트용 경화강 노즐을 둡니다.', ['방식' => 'FDM, 밀폐', '출력 크기' => '220 × 220 × 250 mm', '노즐' => '0.4 mm 경화강', '최소 층' => '약 0.1 mm']],
            'elegoo-saturn-4-ultra' => ['https://en.wikipedia.org/wiki/Stereolithography', '광경화(SLA/MSLA)는 통 안의 레진을 자외선으로 굳혀 쌓는 방식입니다. Saturn 4 Ultra는 405 nm MSLA 프린터입니다.', ['방식' => 'MSLA 레진, 405 nm', '출력 크기' => '218 × 122 × 220 mm', '최소 층' => '약 0.01 mm', '후처리' => '세척 · 경화기 별도']],
            'anycubic-photon-mono-m5s' => ['https://en.wikipedia.org/wiki/Stereolithography', 'Photon Mono M5s는 Anycubic의 405 nm 레진 프린터입니다. 레진은 피부 접촉을 피하고 환기해야 합니다.', ['방식' => 'MSLA 레진, 405 nm', '출력 크기' => '218 × 123 × 200 mm', '최소 층' => '약 0.01 mm']],
            'polymaker-polyterra-pla' => ['https://en.wikipedia.org/wiki/Polylactic_acid', 'PLA는 옥수수 전분 등에서 온 열가소성 플라스틱으로, FDM에서 가장 흔히 쓰는 재료입니다. PolyTerra PLA는 무광 계열입니다.', ['재료' => 'PLA', '노즐' => '190–230°C', '베드' => '25–60°C', '건조' => '55°C, 6시간', '직경' => '1.75 mm']],
            'esun-pla-plus' => ['https://en.wikipedia.org/wiki/Polylactic_acid', 'PLA+는 일반 PLA보다 충격에 버티게 배합한 필라멘트 계열입니다. 배합 비율은 저장하지 않습니다.', ['재료' => 'PLA+', '노즐' => '190–220°C', '베드' => '60–80°C', '건조' => '50°C', '직경' => '1.75 mm']],
            'esun-petg' => ['https://en.wikipedia.org/wiki/Polyethylene_terephthalate', 'PETG는 글리콜을 넣은 PET로, PLA보다 질기고 습기에 예민합니다.', ['재료' => 'PETG', '노즐' => '230–260°C', '베드' => '75–90°C', '건조' => '60°C, 8시간 이상', '챔버' => '권장']],
            'esun-abs' => ['https://en.wikipedia.org/wiki/Acrylonitrile_butadiene_styrene', 'ABS는 아크릴로니트릴·부타디엔·스티렌 공중합체입니다. 수축이 커서 밀폐와 환기가 필요합니다.', ['재료' => 'ABS', '노즐' => '220–260°C', '베드' => '90–110°C', '건조' => '70–80°C', '챔버' => '필요', '주의' => '환기']],
            'elegoo-standard-resin' => ['https://en.wikipedia.org/wiki/Stereolithography', '표준 광경화 레진은 405 nm 자외선에 반응합니다. 장갑과 환기가 필요하고, 출력 후 세척·경화가 따로 있습니다.', ['파장' => '405 nm', '용량' => '1000 g', '보관' => '직사광선을 피해 밀봉', '주의' => '피부 접촉 금지']],
            'anycubic-standard-resin' => ['https://en.wikipedia.org/wiki/Stereolithography', 'Anycubic 표준 레진도 405 nm 광경화 수지입니다. SDS는 제조사 페이지를 따릅니다.', ['파장' => '405 nm', '용량' => '1000 g', '보관' => '차광 밀봉']],
        ];
        foreach (['cat_equipment', 'cat_materials'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'wiki_url')) {
                continue;
            }
            foreach ($wiki as $key => [$url, $summary, $facts]) {
                $row = DB::table($table)->where('key', $key)->first();
                if (! $row) {
                    continue;
                }
                DB::table($table)->where('key', $key)->update([
                    'wiki_url' => $row->wiki_url ?: $url,
                    'summary' => $row->summary ?: $summary,
                    'facts' => $row->facts ?: json_encode($facts, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
