<?php

namespace Modules\Custom\Catalog\Support;

/**
 * 0.2.0 카탈로그 설정 (storage/app/modules/custom-catalog/settings.json) · 자동 수집 상태 (state.json).
 * AI 서버 연결은 AiSettings(ai.json) — 다른 모듈과 같은 꼴.
 */
final class Settings
{
    public static ?string $dirOverride = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'per_page' => 24,
            'menu_enabled' => true,           // 0.2.10 헤더 메인 메뉴에 넣기 (홈 디자인 메뉴 목록과 서로 맞춤)
            'menu_label' => '3D 카탈로그',    // 0.2.10 헤더 메뉴 이름
            'tabs_off' => [],                 // 숨길 탭 키
            'logos' => [],                    // 관리자가 올린 제조사 로고: 정리한 제조사 이름 → 주소 (Logos)
            'image_mode' => 'auto',           // 보여 줄 사진: auto · company · catalog · icon (CatalogService::imageOf)
            'photo_px' => 1100,               // 사진 긴 변
            'photo_quality' => 82,            // JPEG 품질
            'auto' => false,                  // 🌙 조용할 때 자동 수집
            'auto_from' => 1,                 // 시작 시 (0 ~ 23)
            'auto_to' => 7,                   // 끝 시 (같으면 하루 종일)
            'auto_load' => 50,                // 서버 부하 이 % 아래일 때만
            'auto_every' => 10,               // 분 — 한 번 돌고 쉬는 시간
            'auto_per_run' => 2,              // 한 번에 하는 일 수
            'auto_per_day' => 40,             // 하루 최대
            'task_members' => true,           // 재고 관리에서 회원이 등록한 모델 · 재료 가져오기 (AI 안 씀)
            'task_new' => true,               // 새 모델 · 재료 찾기
            'task_fill' => true,              // 빈 제원 채우기
            'task_photo' => true,             // 사진 찾기
            'task_sds' => true,               // 안전 자료(MSDS) 찾기 — 재료
            'apply' => 'review',              // review = 제안함에 쌓고 관리자가 확인 · auto = 바로 반영
            'new_values' => false,            // 0.2.11 새 항목 제안에 AI 가 기억으로 적은 제원 · 소개 · 주소도 넣기 (끄면 이름 · 종류만 — 틀린 값이 많아서)
            'ai_paste' => true,               // 0.2.11 편집 창 「🤖 AI 로 정리해 넣기」 — 붙여 넣은(끌어 놓은) 글 · 주소에서 칸을 채움
            'search' => 'none',               // 사진 · 자료 검색: none · brave
            'brave_key' => '',
        ];
    }

    private static function dir(): string
    {
        if (self::$dirOverride !== null) {
            return self::$dirOverride;
        }
        try {
            return storage_path('app/modules/custom-catalog');
        } catch (\Throwable) {
            return sys_get_temp_dir().'/custom-catalog';
        }
    }

    /** 설정 폴더의 파일 경로 (잠금 파일 등) */
    public static function file(string $name): string
    {
        $d = self::dir();
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d.'/'.$name;
    }

    private static function read(string $name): array
    {
        $p = self::dir().'/'.$name;
        $j = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;

        return is_array($j) ? $j : [];
    }

    private static function write(string $name, array $v): void
    {
        $d = self::dir();
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        file_put_contents($d.'/'.$name, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::normalize(self::read('settings.json'));
    }

    public static function get(string $k): mixed
    {
        return self::all()[$k] ?? null;
    }

    /** @param array<string, mixed> $raw */
    public static function normalize(array $raw): array
    {
        $d = self::defaults();
        $int = static fn (string $k, int $lo, int $hi) => max($lo, min($hi, (int) (is_numeric($raw[$k] ?? null) ? $raw[$k] : $d[$k])));
        $bool = static fn (string $k) => array_key_exists($k, $raw) ? ! in_array($raw[$k], [false, 0, '0', 'false', '', null], true) : $d[$k];
        $tabs = array_column(Fields::TABS, 0);

        return [
            'per_page' => $int('per_page', 8, 96),
            'menu_enabled' => $bool('menu_enabled'),
            'menu_label' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($raw['menu_label'] ?? ''))) ?? ''), 0, 20) ?: $d['menu_label'],
            'tabs_off' => array_values(array_intersect($tabs, is_array($raw['tabs_off'] ?? null) ? $raw['tabs_off'] : [])),
            'logos' => array_slice(array_filter(array_map('strval', is_array($raw['logos'] ?? null) ? $raw['logos'] : []), static fn ($u) => str_starts_with($u, '/api/modules/custom-catalog/')), 0, 400, true),
            'image_mode' => in_array($raw['image_mode'] ?? '', ['company', 'catalog', 'icon'], true) ? $raw['image_mode'] : 'auto',
            'photo_px' => $int('photo_px', 480, 2400),
            'photo_quality' => $int('photo_quality', 50, 95),
            'auto' => $bool('auto'),
            'auto_from' => $int('auto_from', 0, 23),
            'auto_to' => $int('auto_to', 0, 23),
            'auto_load' => $int('auto_load', 5, 100),
            'auto_every' => $int('auto_every', 1, 720),
            'auto_per_run' => $int('auto_per_run', 1, 10),
            'auto_per_day' => $int('auto_per_day', 1, 500),
            'task_members' => $bool('task_members'),
            'task_new' => $bool('task_new'),
            'task_fill' => $bool('task_fill'),
            'task_photo' => $bool('task_photo'),
            'task_sds' => $bool('task_sds'),
            'apply' => ($raw['apply'] ?? '') === 'auto' ? 'auto' : 'review',
            'new_values' => $bool('new_values'),
            'ai_paste' => $bool('ai_paste'),
            'search' => ($raw['search'] ?? '') === 'brave' ? 'brave' : 'none',
            'brave_key' => mb_substr(preg_replace('/\s/', '', (string) ($raw['brave_key'] ?? '')) ?? '', 0, 200),
        ];
    }

    /** 화면에서 온 값 저장 — 키는 비워 보내면 그대로, clear_brave_key 면 지움 @param array<string, mixed> $in */
    public static function save(array $in): array
    {
        $cur = self::all();
        $next = array_merge($cur, array_intersect_key($in, self::defaults()));
        if (! empty($in['clear_brave_key'])) {
            $next['brave_key'] = '';
        } elseif (trim((string) ($in['brave_key'] ?? '')) === '') {
            $next['brave_key'] = $cur['brave_key'];
        }
        $next = self::normalize($next);
        self::write('settings.json', $next);

        return $next;
    }

    /** 관리자 화면용 — 키는 가림 */
    public static function forAdmin(): array
    {
        $s = self::all();
        $k = $s['brave_key'];
        $s['brave_key'] = '';
        $s['brave_key_set'] = $k !== '';
        $s['brave_key_hint'] = $k !== '' ? mb_substr($k, 0, 4).'…'.mb_substr($k, -4) : '';

        return $s;
    }

    /** @return array{last: int, day: string, count: int, turn: int, brand: int, log: list<array{at: string, text: string}>} */
    public static function state(): array
    {
        $s = self::read('state.json');

        return ['last' => (int) ($s['last'] ?? 0), 'day' => (string) ($s['day'] ?? ''), 'count' => (int) ($s['count'] ?? 0), 'turn' => (int) ($s['turn'] ?? 0),
            'brand' => (int) ($s['brand'] ?? 0), 'log' => array_values(array_filter(is_array($s['log'] ?? null) ? $s['log'] : [], 'is_array'))];
    }

    public static function saveState(array $s): void
    {
        $s['log'] = array_slice($s['log'] ?? [], 0, 60);
        self::write('state.json', $s);
    }

    public static function log(string $text): void
    {
        $s = self::state();
        array_unshift($s['log'], ['at' => date('Y-m-d H:i'), 'text' => mb_substr($text, 0, 300)]);
        self::saveState($s);
    }
}
