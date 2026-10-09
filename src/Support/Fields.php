<?php

namespace Modules\Custom\Catalog\Support;

/**
 * 0.2.0 카탈로그 항목 정의 — 종류 · 묶음(탭) · 제원 칸.
 *
 * 종류 키는 업체검색(custom-companies)의 장비 종류(EQUIP_KINDS) · 재고 종류(SPOOL_KINDS)와 같음 — 업체검색이 목록만 뽑아 가도 맞음.
 * 제원 칸: [키, 이름, 꼴, 단위, 쓰는 종류(비면 전부), 고르기 목록]
 *   꼴: num(숫자) · text · bool(예/아니오) · sel(하나 고르기) · tags(여러 개, 쉼표)
 *   표의 칸(build_x_mm …)에 있는 것은 col, 나머지는 specs(JSON)에 저장.
 * 화면(입력 · 상세)과 AI(채워 넣기)가 모두 이 정의 하나로 돈다.
 */
final class Fields
{
    /** 장비 종류 [이름, 이모지] */
    public const EQUIPMENT_KINDS = [
        'fdm' => ['FDM 프린터', '🖨️'], 'sla' => ['SLA 레진 프린터', '🧪'], 'dlp' => ['DLP · LCD 레진 프린터', '💡'],
        'sls' => ['SLS (분말)', '⚗️'], 'mjf' => ['MJF', '🏭'], 'metal' => ['금속 3D 프린터', '🔩'],
        'cnc' => ['CNC 가공기', '⚙️'], 'laser' => ['레이저 커팅 · 각인기', '🔦'], 'scanner' => ['3D 스캐너', '📡'],
        'wash_cure' => ['세척 · 경화기', '🫧'], 'dryer' => ['필라멘트 건조기', '♨️'], 'post' => ['후가공 · 도색 장비', '🎨'],
        'vacuum' => ['진공 성형기', '🌀'], 'injection' => ['사출기', '💉'], 'uv_print' => ['UV 평판 프린터', '🖼️'],
        'plotter' => ['커팅 플로터', '✂️'], 'measure' => ['측정 · 검사 장비', '📏'], 'other' => ['기타 장비', '🧰'],
    ];

    public const MATERIAL_KINDS = ['fdm' => ['필라멘트', '🧵'], 'resin' => ['레진', '🧪'], 'powder' => ['분말 · 금속', '⚗️']];

    /** 화면 탭 [키, 이름, 이모지, 표(equipment|materials), 종류들] */
    public const TABS = [
        ['fdm', 'FDM 프린터', '🖨️', 'equipment', ['fdm']],
        ['resin_printer', '레진 프린터', '💡', 'equipment', ['sla', 'dlp']],
        ['industrial', '산업용 프린터', '🏭', 'equipment', ['sls', 'mjf', 'metal']],
        ['maker', '가공 장비', '⚙️', 'equipment', ['cnc', 'laser', 'plotter', 'uv_print', 'vacuum', 'injection']],
        ['tools', '주변 장비', '🧰', 'equipment', ['scanner', 'wash_cure', 'dryer', 'post', 'measure', 'other']],
        ['filament', '필라멘트', '🧵', 'materials', ['fdm']],
        ['resin', '레진', '🧪', 'materials', ['resin']],
        ['powder', '분말 · 금속', '⚗️', 'materials', ['powder']],
    ];

    private const PRINTERS = ['fdm', 'sla', 'dlp', 'sls', 'mjf', 'metal'];

    private const RESIN = ['sla', 'dlp'];

    private const POWDER = ['sls', 'mjf', 'metal'];

    /** @return list<array{0: string, 1: list<array<int, mixed>>}> 묶음 → 칸 */
    public static function equipment(): array
    {
        $P = self::PRINTERS;
        $R = self::RESIN;
        $W = self::POWDER;

        return [
            ['기본', [
                ['released_on', '출시', 'text', '', [], null, 'col', '예: 2024-06'],
                ['sale', '판매 상태', 'sel', '', [], ['판매 중', '단종', '출시 예정']],
                ['price_krw', '참고 가격', 'num', '원', []],
                ['origin', '제조국', 'text', '', []],
                ['structure', '구조', 'sel', '', ['fdm'], ['CoreXY', '베드슬링거', '델타', 'IDEX', '툴체인저', '벨트', '기타']],
                ['enclosed', '밀폐형', 'bool', '', $P, null, 'col'],
                ['multicolor', '다색 출력', 'bool', '', ['fdm'], null, 'col'],
                ['colors_max', '최대 색 수', 'num', '색', ['fdm']],
                ['ams', '다색 장치', 'text', '', ['fdm'], null, 'spec', '예: AMS 2 Pro, CFS, MMU3'],
            ]],
            ['출력 크기 · 정밀도', [
                ['build_x_mm', '가로 (X)', 'num', 'mm', [], null, 'col'],
                ['build_y_mm', '세로 (Y)', 'num', 'mm', [], null, 'col'],
                ['build_z_mm', '높이 (Z)', 'num', 'mm', [], null, 'col'],
                ['min_layer_um', '최소 층 높이', 'num', 'µm', $P, null, 'col'],
                ['xy_um', 'XY 해상도', 'num', 'µm', array_merge($R, $W)],
                ['accuracy_mm', '정확도', 'text', '', ['scanner', 'measure', 'cnc', 'laser'], null, 'spec', '예: ±0.05 mm'],
            ]],
            ['속도 · 온도', [
                ['speed_max', '최대 속도', 'num', 'mm/s', ['fdm']],
                ['accel_max', '최대 가속도', 'num', 'mm/s²', ['fdm']],
                ['flow_max', '최대 토출량', 'num', 'mm³/s', ['fdm']],
                ['nozzle_temp_max', '노즐 최고 온도', 'num', '°C', ['fdm']],
                ['bed_temp_max', '베드 최고 온도', 'num', '°C', ['fdm']],
                ['chamber_temp_max', '챔버 최고 온도', 'num', '°C', ['fdm', 'sls', 'metal']],
                ['chamber_heated', '챔버 가열', 'bool', '', ['fdm']],
                ['print_speed_h', '출력 속도', 'text', '', array_merge($R, $W), null, 'spec', '예: 150 mm/h'],
            ]],
            ['노즐 · 압출', [
                ['nozzle', '기본 노즐', 'text', '', ['fdm'], null, 'col', '예: 0.4 mm 경화강'],
                ['nozzle_sizes', '쓸 수 있는 노즐', 'tags', '', ['fdm'], ['0.2', '0.4', '0.6', '0.8', '1.0']],
                ['extruder', '압출 방식', 'sel', '', ['fdm'], ['다이렉트', '보우덴']],
                ['toolheads', '툴헤드 수', 'num', '개', ['fdm']],
                ['filament_dia', '필라멘트 직경', 'sel', 'mm', ['fdm'], ['1.75', '2.85']],
                ['bed_surface', '베드 표면', 'text', '', ['fdm'], null, 'spec', '예: 텍스처 PEI'],
                ['leveling', '레벨링', 'sel', '', ['fdm'], ['자동', '반자동', '수동']],
            ]],
            ['광원 · 레진', [
                ['light', '광원', 'sel', '', $R, ['LCD (MSLA)', 'DLP', '레이저 SLA']],
                ['wavelength_nm', '파장', 'num', 'nm', array_merge($R, ['wash_cure', 'laser'])],
                ['lcd_size', 'LCD 크기', 'text', '', $R, null, 'spec', '예: 10.1인치'],
                ['lcd_res', 'LCD 해상도', 'text', '', $R, null, 'spec', '예: 12K (11520×5120)'],
                ['vat_heat', '레진 통 가열', 'bool', '', $R],
                ['auto_feed', '레진 자동 공급', 'bool', '', $R],
                ['tilt', '틸트 박리', 'bool', '', $R],
            ]],
            ['레이저 · 가공', [
                ['laser_type', '레이저 종류', 'sel', '', ['laser', 'sls', 'metal'], ['다이오드', 'CO2', '파이버', '적외선', 'UV']],
                ['laser_w', '레이저 출력', 'num', 'W', ['laser', 'sls', 'metal']],
                ['spindle_w', '스핀들 출력', 'num', 'W', ['cnc']],
                ['spindle_rpm', '스핀들 회전수', 'num', 'rpm', ['cnc']],
                ['axes', '축 수', 'num', '축', ['cnc']],
                ['scan_speed', '스캔 속도', 'text', '', ['scanner'], null, 'spec', '예: 초당 150만 점'],
                ['scan_range', '스캔 범위', 'text', '', ['scanner']],
                ['capacity', '용량', 'text', '', ['wash_cure', 'dryer', 'vacuum', 'injection', 'post'], null, 'spec', '예: 스풀 2개 · 8 L'],
            ]],
            ['재료', [
                ['materials', '쓸 수 있는 재료', 'tags', '', [], null, 'col', '쉼표로 — 예: PLA, PETG, ABS, PA-CF'],
            ]],
            ['편의 · 연결', [
                ['camera', '카메라', 'bool', '', $P],
                ['ai_detect', 'AI 출력 감지', 'bool', '', $P],   // 0.2.12 레진 프린터도 (카메라 AI 오류 감지)
                ['runout', '필라멘트 감지', 'bool', '', ['fdm']],
                ['power_recover', '정전 복구', 'bool', '', $P],
                ['air_filter', '공기 필터', 'bool', '', array_merge($P, ['laser'])],
                ['display', '화면', 'text', '', [], null, 'spec', '예: 5인치 터치'],
                ['connect', '연결', 'tags', '', [], ['Wi-Fi', 'LAN', 'USB', 'SD', '클라우드', '블루투스']],
                ['firmware', '펌웨어', 'text', '', $P, null, 'spec', '예: Klipper, Marlin'],
                ['open_source', '오픈소스', 'bool', '', $P],
                ['slicer', '슬라이서 · 소프트웨어', 'text', '', []],
                ['noise_db', '소음', 'num', 'dB', []],
            ]],
            ['크기 · 전원', [
                ['size_w', '본체 가로', 'num', 'mm', []],
                ['size_d', '본체 세로', 'num', 'mm', []],
                ['size_h', '본체 높이', 'num', 'mm', []],
                ['weight_kg', '무게', 'num', 'kg', []],
                ['power_w', '소비 전력', 'num', 'W', []],
                ['voltage', '전압', 'text', '', [], null, 'spec', '예: 100–240 V'],
            ]],
        ];
    }

    /** @return list<array{0: string, 1: list<array<int, mixed>>}> */
    public static function materials(): array
    {
        return [
            ['기본', [
                ['material', '재료', 'text', '', [], null, 'col', '예: PLA, PETG-CF, ABS-Like'],
                ['color', '색', 'text', '', [], null, 'col'],
                ['color_hex', '색 견본', 'text', '', [], null, 'col', '예: #ff6600'],
                ['colors', '나오는 색', 'tags', '', []],
                ['traits', '특징', 'tags', '', [], ['고속(HS)', '실크', '매트', '카본(CF)', '유리섬유(GF)', '야광', '열변색', '반짝이', '대리석', '우드', '메탈필', '유연', '내열', '내충격', '수용성', '식품용', 'UV 강함', '난연'], 'col'],
                ['diameter', '직경', 'sel', 'mm', ['fdm'], ['1.75', '2.85'], 'col'],
                ['weight_g', '용량', 'num', 'g', [], null, 'col'],
                ['spool', '스풀', 'text', '', ['fdm'], null, 'spec', '예: 종이 스풀 · 리필 가능'],
                ['sale', '판매 상태', 'sel', '', [], ['판매 중', '단종', '출시 예정']],
                ['price_krw', '참고 가격', 'num', '원', []],
                ['origin', '제조국', 'text', '', []],
            ]],
            ['출력 조건', [
                ['nozzle_min', '노즐 최저', 'num', '°C', ['fdm'], null, 'col'],
                ['nozzle_max', '노즐 최고', 'num', '°C', ['fdm'], null, 'col'],
                ['bed_min', '베드 최저', 'num', '°C', ['fdm'], null, 'col'],
                ['bed_max', '베드 최고', 'num', '°C', ['fdm'], null, 'col'],
                ['chamber', '챔버', 'sel', '', ['fdm'], ['필요', '권장', '불필요'], 'col'],
                ['speed_max', '권장 최대 속도', 'num', 'mm/s', ['fdm']],
                ['fan', '냉각 팬', 'text', '', ['fdm'], null, 'spec', '예: 100% · ABS 는 0–30%'],
                ['bed_type', '권장 베드', 'text', '', ['fdm']],
                ['abrasive', '경화 노즐 필요', 'bool', '', ['fdm']],
                ['ams_ok', '다색 장치 사용', 'bool', '', ['fdm']],
                ['wavelength_nm', '파장', 'num', 'nm', ['resin']],
                ['exposure_s', '일반 노출', 'num', '초', ['resin']],
                ['bottom_exposure_s', '바닥 노출', 'num', '초', ['resin']],
                ['wash', '세척', 'sel', '', ['resin'], ['IPA', '물', '전용 세척액']],
                ['cure_min', '후경화', 'num', '분', ['resin']],
                ['sinter_temp', '소결 · 가공 온도', 'num', '°C', ['powder']],
                ['particle_um', '입자 크기', 'text', '', ['powder'], null, 'spec', '예: 15–45 µm'],
            ]],
            ['건조 · 보관', [
                ['dry_temp', '건조 온도', 'num', '°C', ['fdm', 'powder'], null, 'col'],
                ['dry_hours', '건조 시간', 'num', '시간', ['fdm', 'powder'], null, 'col'],
                ['moisture', '습기 민감도', 'sel', '', ['fdm', 'powder'], ['낮음', '보통', '높음']],
                ['storage_note', '보관', 'text', '', [], null, 'col'],
                ['shelf_months', '유통 기한', 'num', '개월', []],
            ]],
            ['물성', [
                ['density', '밀도', 'num', 'g/cm³', []],
                ['tensile_mpa', '인장 강도', 'num', 'MPa', []],
                ['flex_mpa', '굴곡 강도', 'num', 'MPa', []],
                ['modulus_mpa', '탄성률', 'num', 'MPa', []],
                ['elong', '파단 연신율', 'num', '%', []],
                ['impact', '충격 강도', 'text', '', [], null, 'spec', '예: 5 kJ/m²'],
                ['hdt_c', '열변형 온도', 'num', '°C', []],
                ['shore', '경도', 'text', '', [], null, 'spec', '예: 95A · 84D'],
                ['viscosity', '점도', 'num', 'mPa·s', ['resin']],
                ['shrink', '수축률', 'num', '%', ['resin', 'powder']],
            ]],
            ['안전', [
                ['food_safe', '식품 접촉 가능', 'bool', '', []],
                ['odor', '냄새', 'sel', '', [], ['거의 없음', '약함', '강함']],
                ['caution', '주의', 'text', '', [], null, 'col'],
                ['sds_url', '안전 자료 (MSDS)', 'url', '', [], null, 'col'],
            ]],
        ];
    }

    /** @return list<array{0: string, 1: list<array<int, mixed>>}> */
    public static function of(string $type): array
    {
        return $type === 'materials' ? self::materials() : self::equipment();
    }

    /** 칸 하나를 화면용 꼴로 @param array<int, mixed> $f */
    public static function field(array $f): array
    {
        return ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'unit' => $f[3] ?? '', 'kinds' => $f[4] ?? [], 'options' => $f[5] ?? null,
            'col' => ($f[6] ?? 'spec') === 'col', 'hint' => $f[7] ?? ''];
    }

    /** @return list<array<string, mixed>> 그 종류에 쓰는 칸 (묶음 이름 포함) */
    public static function forKind(string $type, string $kind): array
    {
        $out = [];
        foreach (self::of($type) as [$group, $fields]) {
            foreach ($fields as $f) {
                $d = self::field($f);
                if ($d['kinds'] === [] || in_array($kind, $d['kinds'], true)) {
                    $out[] = $d + ['group' => $group];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> 화면이 한 번 받아 두는 정의 */
    public static function meta(): array
    {
        $groups = static fn (string $type) => array_map(static fn ($g) => ['group' => $g[0], 'fields' => array_map([self::class, 'field'], $g[1])], self::of($type));
        $kinds = static fn (array $list) => array_map(static fn ($k, $v) => ['key' => $k, 'label' => $v[0], 'icon' => $v[1]], array_keys($list), $list);

        return [
            'tabs' => array_map(static fn ($t) => ['key' => $t[0], 'label' => $t[1], 'icon' => $t[2], 'type' => $t[3], 'kinds' => $t[4]], self::TABS),
            'kinds' => ['equipment' => $kinds(self::EQUIPMENT_KINDS), 'materials' => $kinds(self::MATERIAL_KINDS)],
            'fields' => ['equipment' => $groups('equipment'), 'materials' => $groups('materials')],
        ];
    }

    public static function kindLabel(string $type, string $kind): string
    {
        $l = $type === 'materials' ? self::MATERIAL_KINDS : self::EQUIPMENT_KINDS;

        return $l[$kind][0] ?? $kind;
    }

    public static function validKind(string $type, string $kind): bool
    {
        return isset(($type === 'materials' ? self::MATERIAL_KINDS : self::EQUIPMENT_KINDS)[$kind]);
    }

    /** 값 정리 — 꼴에 맞지 않으면 null @param array<string, mixed> $d */
    public static function clean(array $d, mixed $v): mixed
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        switch ($d['type']) {
            case 'num':
                if (is_string($v)) {
                    $v = str_replace([',', ' '], '', $v);
                    if (preg_match('/-?\d+(\.\d+)?/', $v, $m)) {
                        $v = $m[0];
                    }
                }
                if (! is_numeric($v)) {
                    return null;
                }
                $n = (float) $v;

                return $n < 0 || $n > 100000000 ? null : (floor($n) == $n ? (int) $n : round($n, 3));
            case 'bool':
                if (is_bool($v)) {
                    return $v;
                }
                $s = mb_strtolower(trim((string) $v));
                if (in_array($s, ['1', 'true', 'yes', 'y', '예', '있음', '지원', 'o'], true)) {
                    return true;
                }

                return in_array($s, ['0', 'false', 'no', 'n', '아니오', '없음', '미지원', 'x'], true) ? false : null;
            case 'tags':
                $list = is_array($v) ? $v : (preg_split('/[,\n、·]+/u', (string) $v) ?: []);
                $out = [];
                foreach ($list as $t) {
                    $t = mb_substr(trim(strip_tags(is_scalar($t) ? (string) $t : '')), 0, 40);
                    if (($d['key'] ?? '') === 'connect') {
                        // 0.2.12 같은 뜻은 보기 이름으로 (Ethernet · 이더넷 · 유선 → LAN, WiFi → Wi-Fi …)
                        $t = match (mb_strtolower(preg_replace('/[\s\-_]+/', '', $t) ?? $t)) {
                            'ethernet', '이더넷', '유선', '유선lan', 'rj45', 'lan' => 'LAN',
                            'wifi', 'wlan', '와이파이', '무선', '무선lan' => 'Wi-Fi',
                            'usb', 'usb메모리', 'usb드라이브' => 'USB',
                            'sd', 'sd카드', 'microsd', 'tf', 'tf카드' => 'SD',
                            'bluetooth', '블루투스' => '블루투스',
                            'cloud', '클라우드' => '클라우드',
                            default => $t,
                        };
                    }
                    if ($t !== '' && ! in_array($t, $out, true) && count($out) < 40) {
                        $out[] = $t;
                    }
                }

                return $out ?: null;
            case 'url':
                $u = trim((string) $v);

                return preg_match('#^https?://[^\s<>"]+$#i', $u) ? mb_substr($u, 0, 500) : null;
            default:
                if (is_array($v)) {
                    $v = implode(', ', array_filter($v, 'is_scalar'));
                }
                $s = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)) ?? ''), 0, 200);

                return $s === '' ? null : $s;
        }
    }

    /** 보이는 글 @param array<string, mixed> $d */
    public static function show(array $d, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '';
        }
        if ($d['type'] === 'bool') {
            return $v ? '예' : '아니오';
        }
        if (is_array($v)) {
            return implode(' · ', array_map('strval', $v));
        }
        if ($d['type'] === 'num' && is_numeric($v)) {
            $n = (float) $v;
            $v = $d['key'] === 'price_krw' || $n >= 10000 ? number_format($n) : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        }

        return trim($v.' '.($d['unit'] ?? ''));
    }
}
