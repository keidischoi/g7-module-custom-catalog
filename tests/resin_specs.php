<?php

/** 0.3.24 레진 공식 제원 — php tests/resin_specs.php */

declare(strict_types=1);

namespace Modules\Custom\Catalog\Support {
    final class Schema
    {
    }
}

namespace {
    require dirname(__DIR__).'/src/Support/ResinSpecs.php';

    use Modules\Custom\Catalog\Support\ResinSpecs as R;

    $bad = 0;
    $ok = static function (bool $c, string $m) use (&$bad): void {
        echo($c ? 'ok   ' : 'FAIL ').$m."\n";
        $bad += $c ? 0 : 1;
    };

    $d = R::data();
    $keys = [];
    foreach ($d['items'] as $it) {
        $n = implode(',', $it['keys']).($it['create']['title'] ?? '');
        $keys = array_merge($keys, $it['keys']);
        $ok(str_starts_with($it['url'] ?? '', 'https://') || ($it['grade'] ?? '') === 'slicer', "출처 주소 {$n}");
        foreach ($it['ranges'] ?? [] as $k => $r) {
            if ($r[0] >= $r[1]) {
                $ok(false, "범위 {$n} {$k}");
            }
        }
        foreach ($it['profiles'] ?? [] as $p) {
            if ($p['exposure_s'] <= 0 || $p['exposure_s'] > 20 || $p['bottom_exposure_s'] < 5 || $p['bottom_exposure_s'] > 120 || $p['layer_mm'] <= 0 || $p['layer_mm'] > 0.3) {
                $ok(false, "프로필 범위 밖 {$n} {$p['printer']}");
            }
        }
        $v = R::values($it);
        if (isset($v['density']) && ($v['density'] < 0.9 || $v['density'] > 1.6)) {
            $ok(false, "밀도 범위 밖 {$n}");
        }
    }
    $ok(count($keys) === count(array_unique($keys)), '제품 키 겹침 없음');

    $it = ['url' => 'https://x.test', 'values' => ['tensile_mpa' => 40], 'ranges' => ['viscosity' => [300, 400], 'exposure_s' => [2, 3]], 'wash' => 'IPA', 'profiles' => [['printer' => 'P']]];
    $s = R::merge(['tensile_mpa' => 30, 'memo' => 'x'], $it, '2026-11-01', 'official');
    $ok($s['tensile_mpa'] === 30, '있는 값은 덮어쓰지 않음');
    $ok($s['resin_src']['conflicts']['tensile_mpa'] === 40, '다른 값은 conflicts 에');
    $ok(($s['viscosity'] ?? 0) == 350, '범위의 가운데 값');
    $ok(! isset($s['exposure_s']), '노출은 가운데 값을 만들지 않음');
    $ok($s['memo'] === 'x' && $s['wash'] === 'IPA' && count($s['print_profiles']) === 1, '다른 칸 유지 · 프로필 저장');
    $sl = R::merge([], ['grade' => 'slicer', 'values' => []], '2026-11-01', 'official');
    $ok($sl['resin_src']['grade'] === 'slicer' && ! isset($sl['resin_src']['url']), '슬라이서 자료는 주소 없이');
    $ok(R::view([]) === null && R::view($s)['src']['url'] === 'https://x.test', '자료 없으면 null');

    echo $bad ? "실패 {$bad}\n" : "모두 통과\n";
    exit($bad ? 1 : 0);
}
