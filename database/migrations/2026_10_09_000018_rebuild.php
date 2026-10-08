<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Custom\Catalog\Services\CatalogService;
use Modules\Custom\Catalog\Support\Fields;
use Modules\Custom\Catalog\Support\Schema;

/**
 * 0.2.0 다시 만들기
 *  ① 모자란 칸 · 표 (specs · 제안함 …)
 *  ② 예전 자료 정리 — 틀에 박힌 소개 글 · 겹치는 「기타」 줄을 지우고, 글로 적혀 있던 제원(최대속도 · 무게 · 판매가 …)을 제 칸으로 옮김
 *  ③ 더 많은 장비 · 재료 이름 (제원은 비워 둠 — 관리자나 자동 수집이 채움). 이미 있는 것은 건드리지 않음.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::reset();
        Schema::ensure();
        $this->tidy();
        $this->seed();
    }

    public function down(): void {}

    private function tidy(): void
    {
        foreach (DB::table(Schema::EQUIPMENT)->get() as $r) {
            $facts = Schema::json($r->facts ?? null);
            $specs = Schema::json($r->specs ?? null);
            $up = [];
            $boiler = trim((string) ($facts['방식'] ?? ''));
            $num = static fn (string $s) => preg_match('/[\d,.]+/', $s, $m) ? (float) str_replace(',', '', $m[0]) : null;
            foreach ($facts as $k => $v) {
                $v = trim((string) $v);
                $done = true;
                if ($k === '최대속도' && ($n = $num($v))) {
                    $specs['speed_max'] ??= (int) $n;
                } elseif ($k === '무게' && ($n = $num($v))) {
                    $specs['weight_kg'] ??= $n;
                } elseif ($k === '판매가' && ($n = $num($v))) {
                    $specs['price_krw'] ??= (int) $n;
                } elseif ($k === '제품 크기' && preg_match('/(\d+)\D+(\d+)\D+(\d+)/', $v, $m)) {
                    $specs['size_w'] ??= (int) $m[1];
                    $specs['size_d'] ??= (int) $m[2];
                    $specs['size_h'] ??= (int) $m[3];
                } elseif ($k === '소비전력' && ($n = $num($v))) {
                    $specs['power_w'] ??= (int) $n;
                } elseif ($k === '가용') {
                    if (str_starts_with($v, '단종')) {
                        $specs['sale'] ??= '단종';
                    }
                } elseif ($k === '가용소재' || $k === '지원재료') {
                    if (empty(Schema::json($r->materials ?? null))) {
                        $up['materials'] = json_encode(array_values(array_filter(array_map('trim', preg_split('/[,·]+/u', $v) ?: []))), JSON_UNESCAPED_UNICODE);
                    }
                } elseif (! in_array($k, ['출력 크기', '다색', '방식', '주의', '출처', '재료', '노즐', '베드', '제조'], true)) {
                    $done = false;
                }
                if ($done) {
                    unset($facts[$k]);
                }
            }
            $up['facts'] = $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null;
            $up['specs'] = $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;
            // 틀에 박힌 소개 글 (「… FDM입니다. PLA가 기본이고 …」)은 비움 — 제대로 된 소개로 다시 채우게
            if (($boiler !== '' && str_contains((string) ($r->summary ?? ''), $boiler)) || preg_match('/판매처마다 다릅니다|공개 자료 기준/u', (string) ($r->summary ?? ''))) {
                $up['summary'] = null;
            }
            if (in_array(trim((string) ($r->note ?? '')), ['업체검색 장비 목록의 공개 제원'], true)) {
                $up['note'] = null;
            }
            // 「더 알아보기」 칸에 제조사 첫 화면 주소가 들어 있던 것 → 제조사 페이지로
            $wiki = trim((string) ($r->wiki_url ?? ''));
            if ($wiki !== '' && ! str_contains($wiki, 'wikipedia.org')) {
                if (trim((string) ($r->homepage_url ?? '')) === '') {
                    $up['homepage_url'] = $wiki;
                }
                $up['wiki_url'] = null;
            }
            DB::table(Schema::EQUIPMENT)->where('id', $r->id)->update($up);
        }
        foreach (DB::table(Schema::MATERIALS)->get() as $r) {
            $up = [];
            $facts = Schema::json($r->facts ?? null);
            foreach (['재료', '노즐', '베드', '건조', '직경', '챔버', '파장', '용량', '보관'] as $k) {
                unset($facts[$k]);
            }
            $up['facts'] = $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null;
            $ch = ['required' => '필요', 'recommended' => '권장', 'none' => '불필요'][(string) ($r->chamber ?? '')] ?? null;
            if ($ch) {
                $up['chamber'] = $ch;
            }
            if (in_array(trim((string) ($r->traits ?? '')), ['기본', 'PLA+'], true)) {
                $up['traits'] = null;
            }
            // 종류 이름만 있던 줄(제조사 「종류」)은 제품이 아니라서 보관
            if ($r->brand === '종류') {
                $up['status'] = 'archived';
            }
            DB::table(Schema::MATERIALS)->where('id', $r->id)->update($up);
        }
    }

    private function seed(): void
    {
        $file = dirname(__DIR__, 2).'/resources/assets/seed-0.2.json';
        $seed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($seed)) {
            return;
        }
        $svc = new CatalogService();
        foreach (['equipment' => 'model', 'materials' => 'name'] as $type => $tcol) {
            $have = [];
            foreach (DB::table(Schema::table($type))->get(['brand', $tcol, 'kind']) as $r) {
                $have[CatalogService::norm($r->brand).'|'.CatalogService::norm($r->{$tcol})] = true;
            }
            foreach ($seed[$type] ?? [] as $row) {
                $k = CatalogService::norm($row['brand']).'|'.CatalogService::norm($row[$tcol]);
                // 「Prusa Research · Original Prusa MK4S」 처럼 제조사 이름이 조금 달라도 같은 모델이면 건너뜀
                if (isset($have[$k]) || ! Fields::validKind($type, (string) $row['kind'])) {
                    continue;
                }
                $have[$k] = true;
                try {
                    $svc->save($type, ['kind' => $row['kind'], 'brand' => $row['brand'], 'title' => $row[$tcol], 'values' => $type === 'materials' ? ['material' => $row['material']] : []]);
                } catch (\Throwable) {
                }
            }
        }
    }
};
