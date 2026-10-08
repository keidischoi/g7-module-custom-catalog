<?php

namespace Modules\Custom\Catalog\Support;

/**
 * custom-jobs 0.2.0: custom-ad 의 같은 이름 클래스를 그대로 옮김 (광고 AI 연결과 따로 저장 — storage/app/modules/custom-jobs/ai.json).
 * 🔌 배너 편집기 AI 서버 연결 (0.4.3) — custom-home_design 0.7.37 HomeAiSettings 를 그대로 옮김.
 * 레이아웃 편집기(홈 디자인) AI 설정과 **따로** 저장: storage/app/modules/custom-ad/ai.json (같이 쓰지 않음).
 *
 *   { enabled, members(회원 편집기에서도), failover, timeout(초), max_tokens, rev,
 *     servers: [{ id, name, enabled, provider: claude|openai|gemini|grok|ollama, url, api_key, timeout(초, 0 = 전체 값), models: [{ name, enabled }] }] }
 *   - 안 되면 다음 모델 → 다음 서버로 (failover)
 * API 키는 관리자 화면으로 내보내지 않음 — 「저장됨 · 앞뒤 4글자」만. 비워 두고 저장하면 같은 id 서버의 키 그대로, clear_key 면 지움.
 * 0.4.4 관리자 화면(광고 관리자 › 배너 AI 연결)은 레이아웃 AI 카드와 같은 꼴 — 서버 여러 개(순위 · 더하기 · 지우기 · 위아래) ·
 * 서버마다 모델 순위 목록 · 🔎 모델 찾기(+ 자동 고르기 pickModel) · 서버마다 기다리는 시간 · 연결 테스트. 바뀔 때마다 save() (rev 확인).
 * 0.4.3 서버 하나 칸(fromForm)도 그대로 받음.
 * 순수 PHP (tests/ad/banner_ai.php).
 */
final class AiSettings
{
    public const PROVIDERS = ['claude', 'openai', 'gemini', 'grok', 'ollama'];

    public const MAX_SERVERS = 10;

    public const MAX_MODELS = 20;

    /** 비워 두면 쓸 주소 */
    public const URLS = [
        'claude' => 'https://api.anthropic.com/v1',
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'grok' => 'https://api.x.ai/v1',
        'ollama' => 'http://localhost:11434',
    ];

    /** 모델이 하나도 없을 때 쓸 모델 · 화면 안내 */
    public const MODELS = [
        'claude' => 'claude-sonnet-5-5',
        'openai' => 'gpt-4.1',
        'gemini' => 'gemini-2.5-flash',
        'grok' => 'grok-3',
        'ollama' => 'qwen2.5:14b',
    ];

    public const NAMES = ['claude' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'grok' => 'Grok', 'ollama' => 'Ollama'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['enabled' => false, 'members' => true, 'failover' => true, 'timeout' => 120, 'max_tokens' => 4000, 'servers' => [], 'rev' => 0];
    }

    /** 저장 값 → 정리 @return array{enabled: bool, failover: bool, timeout: int, max_tokens: int, servers: list<array<string, mixed>>, rev: int} */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $r = is_array($raw) ? $raw : [];
        $d = self::defaults();
        // 0.5.1 한 서버 → 첫 서버
        if (! isset($r['servers']) && (isset($r['provider']) || isset($r['key']) || isset($r['model']) || isset($r['url'])) && trim((string) ($r['model'] ?? '').(string) ($r['key'] ?? '').(string) ($r['api_key'] ?? '')) !== '') {
            $p = in_array($r['provider'] ?? '', self::PROVIDERS, true) ? (string) $r['provider'] : 'ollama';
            $r['servers'] = [['id' => 's1', 'name' => self::NAMES[$p], 'enabled' => true, 'provider' => $p, 'url' => (string) ($r['url'] ?? ''),
                'api_key' => (string) ($r['key'] ?? $r['api_key'] ?? ''), 'models' => trim((string) ($r['model'] ?? '')) !== '' ? [(string) $r['model']] : []]];
        }
        $servers = [];
        $ids = [];
        foreach (is_array($r['servers'] ?? null) ? $r['servers'] : [] as $sv) {
            if (! is_array($sv) || count($servers) >= self::MAX_SERVERS) {
                continue;
            }
            $id = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) ($sv['id'] ?? ''))) ?? '', 0, 12);
            if ($id === '' || isset($ids[$id])) {
                $i = 1;
                while (isset($ids['s'.$i])) {
                    $i++;
                }
                $id = 's'.$i;
            }
            $ids[$id] = true;
            $p = in_array($sv['provider'] ?? '', self::PROVIDERS, true) ? (string) $sv['provider'] : 'ollama';
            $name = mb_substr(trim(preg_replace('/[<>{}\[\]]/u', '', strip_tags((string) ($sv['name'] ?? ''))) ?? ''), 0, 30);
            $servers[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : self::NAMES[$p].' '.(count($servers) + 1),
                'enabled' => self::bool($sv['enabled'] ?? true),
                'provider' => $p,
                'url' => self::cleanUrl((string) ($sv['url'] ?? '')),
                'api_key' => mb_substr(preg_replace('/\s/', '', (string) ($sv['api_key'] ?? '')) ?? '', 0, 400),
                'models' => self::models($sv['models'] ?? []),
                'timeout' => max(0, min(600, (int) ($sv['timeout'] ?? 0))),   // 0.4.4 서버마다 (0 = 전체 값)
                'vision' => self::bool($sv['vision'] ?? false),               // 0.9.31 📷 사진용 서버 — 이 서버의 모델은 모두 사진 읽기에 씀
            ];
        }

        return [
            'enabled' => self::bool($r['enabled'] ?? $d['enabled']),
            'members' => self::bool($r['members'] ?? $d['members']),
            'failover' => self::bool($r['failover'] ?? $d['failover']),
            'timeout' => max(10, min(600, (int) ($r['timeout'] ?? $d['timeout']) ?: $d['timeout'])),
            'max_tokens' => max(500, min(32000, (int) ($r['max_tokens'] ?? $d['max_tokens']) ?: $d['max_tokens'])),
            'servers' => $servers,
            'rev' => max(0, min(PHP_INT_MAX - 1, (int) (is_numeric($r['rev'] ?? null) ? $r['rev'] : 0))),
        ];
    }

    /** 저장된 것이 있는지 (서버가 있거나 AI 를 켜 둠) */
    public static function hasSaved(mixed $current): bool
    {
        $cur = self::normalize($current);

        return $cur['servers'] !== [] || $cur['enabled'];
    }

    /**
     * 0.7.34 덮어쓰기 전 확인 — 문제가 없으면 null, 있으면 이유(관리자에게 보일 말)
     *  - rev 를 안 보냄: 화면이 저장된 설정을 불러오지 못한 채(빈 기본값) 보낸 것 → 저장된 것이 있으면 거절
     *  - rev 가 다름: 다른 창 · 다른 관리자가 그 사이 저장함 → 거절 (오래된 목록으로 서버 · 모델을 지우지 않게)
     *
     * @param  array<string, mixed>  $in
     */
    public static function conflict(array $in, mixed $current): ?string
    {
        $cur = self::normalize($current);
        $rev = $in['rev'] ?? null;
        if ($rev === null || $rev === '' || ! is_numeric($rev)) {
            return self::hasSaved($cur) ? '화면이 저장된 AI 연결 설정을 아직 불러오지 못했습니다 — 저장된 값을 다시 불러왔어요. 다시 바꿔 주세요.' : null;
        }
        if ((int) $rev !== $cur['rev']) {
            return '다른 창(또는 다른 관리자)에서 AI 연결 설정이 바뀌었습니다 — 저장된 값을 다시 불러왔어요. 다시 바꿔 주세요.';
        }

        return null;
    }

    /**
     * 모델 목록 — [{name, enabled}] 또는 이름 목록 · 「a, b」 글 (중복 · 이상한 글자 빼고, 순서 = 순위)
     *
     * @return list<array{name: string, enabled: bool}>
     */
    public static function models(mixed $v): array
    {
        $list = is_array($v) ? $v : (preg_split('/[,\n\r]+/', (string) $v) ?: []);
        $out = [];
        $seen = [];
        foreach ($list as $m) {
            $name = mb_substr(preg_replace('/[\s"<>]/', '', is_array($m) ? (string) ($m['name'] ?? '') : (string) $m) ?? '', 0, 120);
            if ($name === '' || isset($seen[$name]) || count($out) >= self::MAX_MODELS) {
                continue;
            }
            $seen[$name] = true;
            // 0.9.31 vision = 📷 사진용 (사진으로 이력서 자동 입력에 쓸 모델 — 그림을 읽는 모델만 체크)
            $out[] = ['name' => $name, 'enabled' => is_array($m) ? self::bool($m['enabled'] ?? true) : true, 'vision' => is_array($m) && self::bool($m['vision'] ?? false)];
        }

        return $out;
    }

    /**
     * 화면에서 온 값 + 저장된 값 → 저장할 값 (보내지 않은 칸은 그대로 · 서버 키를 비워 보내면 같은 id 서버의 저장된 키)
     *
     * @param  array<string, mixed>  $in
     */
    public static function merge(array $in, mixed $current): array
    {
        $cur = self::normalize($current);
        if (isset($in['servers']) && is_array($in['servers'])) {
            $in['servers'] = array_map(static fn ($sv) => is_array($sv) ? self::keepKey($sv, $cur['servers']) : $sv, array_values($in['servers']));
        }

        $next = self::normalize(array_merge($cur, array_intersect_key($in, self::defaults())));
        $next['rev'] = $cur['rev'] + 1;   // 0.7.34 저장마다 +1 (보낸 rev 는 확인용일 뿐)

        return $next;
    }

    /**
     * 화면에서 온 서버 하나 — 키가 비었으면 저장된 키 (clear_key 면 지움)
     *
     * @param  array<string, mixed>  $sv
     * @param  list<array<string, mixed>>  $saved
     * @return array<string, mixed>
     */
    public static function keepKey(array $sv, array $saved): array
    {
        if (! empty($sv['clear_key'])) {
            $sv['api_key'] = '';
        } elseif (trim((string) ($sv['api_key'] ?? '')) === '') {
            $sv['api_key'] = '';
            foreach ($saved as $o) {
                if (($o['id'] ?? null) === ($sv['id'] ?? '')) {
                    $sv['api_key'] = (string) ($o['api_key'] ?? '');
                }
            }
        }

        return $sv;
    }

    /** 화면에서 온 서버 하나 → 정리된 서버 (저장된 키 채움) @param array<string, mixed> $in */
    public static function formServer(array $in, mixed $current): ?array
    {
        $cur = self::normalize($current);
        $n = self::normalize(['servers' => [self::keepKey($in, $cur['servers'])]]);

        return $n['servers'][0] ?? null;
    }

    /** 관리자 화면용 — 키는 가림 @return array<string, mixed> */
    public static function forAdmin(mixed $raw, bool $loaded = true): array
    {
        $s = self::normalize($raw);
        if (! $loaded) {
            unset($s['rev']);   // 0.7.34 불러오지 못한 값 — 저장 때 conflict 로 걸러짐
        }
        foreach ($s['servers'] as &$sv) {
            $k = $sv['api_key'];
            $sv['api_key'] = '';
            $sv['key_set'] = $k !== '';
            $sv['key_hint'] = $k !== '' ? mb_substr($k, 0, 4).'…'.mb_substr($k, -4) : '';
        }
        unset($sv);
        $s['providers'] = array_map(static fn ($p) => ['value' => $p, 'name' => self::NAMES[$p], 'url' => self::URLS[$p], 'model' => self::MODELS[$p]], self::PROVIDERS);

        return $s;
    }

    /** 실제로 쓸 주소 (비워 두면 종류별 기본) @param array<string, mixed> $sv */
    public static function url(array $sv): string
    {
        return ($sv['url'] ?? '') !== '' ? (string) $sv['url'] : self::URLS[$sv['provider'] ?? 'claude'];
    }

    /** 켜진 모델 이름 (없으면 종류별 기본 하나) @param array<string, mixed> $sv @return list<string> */
    public static function enabledModels(array $sv): array
    {
        $m = array_values(array_map(static fn ($x) => $x['name'], array_filter($sv['models'] ?? [], static fn ($x) => ! empty($x['enabled']))));

        return $m ?: (($sv['models'] ?? []) ? [] : [self::MODELS[$sv['provider'] ?? 'claude']]);
    }

    /**
     * 순위대로 물어볼 차례 — [서버, 모델] (켜진 서버 · 키 있는 서버 · 켜진 모델), failover 를 끄면 맨 앞 하나만
     *
     * @return list<array{server: array<string, mixed>, model: string}>
     */
    public static function order(array $s, ?bool $vision = null): array
    {
        $out = [];
        $flag = [];
        foreach ($s['servers'] as $sv) {
            if (empty($sv['enabled']) || ($sv['provider'] !== 'ollama' && $sv['api_key'] === '')) {
                continue;
            }
            $isVision = [];
            foreach ($sv['models'] ?? [] as $md) {
                $isVision[(string) $md['name']] = ! empty($md['vision']);
            }
            foreach (self::enabledModels($sv) as $m) {
                $out[] = ['server' => $sv, 'model' => $m];
                $flag[] = ! empty($sv['vision']) || ($isVision[$m] ?? false);   // 서버를 사진용으로 켰거나, 그 모델만 사진용으로 체크
            }
        }
        // 0.9.31 📷 사진용으로 체크한 모델: 사진 요청($vision = true)에는 그 모델만(하나도 체크 안 했으면 예전처럼 모두),
        //        글 요청($vision = false)에는 체크 안 한 모델을 먼저 쓰고 사진용은 맨 뒤(다른 모델이 없거나 다 실패했을 때만)
        if ($vision !== null && in_array(true, $flag, true)) {
            $yes = [];
            $no = [];
            foreach ($out as $i => $o) {
                if ($flag[$i]) {
                    $yes[] = $o;
                } else {
                    $no[] = $o;
                }
            }
            $out = $vision ? $yes : array_merge($no, $yes);
        }

        return $s['failover'] ? $out : array_slice($out, 0, 1);
    }

    /** 쓸 수 있는지 @return array{enabled: bool, reason: string} */
    public static function available(array $s): array
    {
        if (! $s['enabled']) {
            return ['enabled' => false, 'reason' => '관리자 › 3D 카탈로그 › 설정 › AI 연결에서 AI 를 켜 주세요.'];
        }
        if (! $s['servers']) {
            return ['enabled' => false, 'reason' => '관리자 › 3D 카탈로그 › 설정 › AI 연결에 서버 주소 · 모델을 넣어 주세요.'];
        }
        if (! self::order($s)) {
            return ['enabled' => false, 'reason' => '쓸 수 있는 서버 · 모델이 없습니다 (켜짐 · API 키 · 모델 확인).'];
        }

        return ['enabled' => true, 'reason' => ''];
    }

    /** 회원 편집기에서 쓸 수 있는지 (관리자는 available 만) @return array{enabled: bool, reason: string} */
    public static function availableFor(array $s, bool $admin): array
    {
        $a = self::available($s);
        if ($a['enabled'] && ! $admin && ! $s['members']) {
            return ['enabled' => false, 'reason' => 'AI 도우미는 지금 관리자만 쓸 수 있어요.'];
        }
        if (! $a['enabled'] && ! $admin) {
            return ['enabled' => false, 'reason' => 'AI 도우미가 아직 준비되지 않았어요. 직접 써 주세요 (사이트 관리자가 AI 를 연결하면 쓸 수 있어요).'];
        }

        return $a;
    }

    /**
     * 관리자 카드(서버 하나) → 저장 꼴. 보내지 않은 칸은 저장된 값 그대로, 키를 비우면 저장된 키, clear_key 면 지움.
     *
     * @param  array<string, mixed>  $in  {enabled, members, failover, timeout, max_tokens, provider, url, api_key, clear_key, models}
     */
    public static function fromForm(array $in, mixed $current): array
    {
        $cur = self::normalize($current);
        $first = $cur['servers'][0] ?? ['id' => 's1', 'name' => '', 'enabled' => true, 'provider' => 'ollama', 'url' => '', 'api_key' => '', 'models' => []];
        $sv = $first;
        foreach (['provider', 'url', 'models'] as $k) {
            if (array_key_exists($k, $in)) {
                $sv[$k] = $in[$k];
            }
        }
        if (array_key_exists('provider', $in) && ($in['provider'] ?? '') !== ($first['provider'] ?? '')) {
            $sv['name'] = '';   // 종류를 바꾸면 이름도 새로
        }
        $sv['api_key'] = (string) ($in['api_key'] ?? '');
        $sv['clear_key'] = ! empty($in['clear_key']) && self::bool($in['clear_key']);
        $out = [];
        foreach (['enabled', 'members', 'failover', 'timeout', 'max_tokens'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = $in[$k];
            }
        }
        $out['servers'] = [self::keepKey($sv, $cur['servers'])];
        $out['rev'] = $cur['rev'];

        return self::merge($out, $cur);
    }

    /** 관리자 카드용 평평한 값 (키는 가림) @return array<string, mixed> */
    public static function forForm(mixed $raw): array
    {
        $a = self::forAdmin($raw);
        $sv = $a['servers'][0] ?? null;
        $s = self::normalize($raw);

        return [
            'enabled' => $s['enabled'], 'members' => $s['members'], 'failover' => $s['failover'], 'timeout' => $s['timeout'], 'max_tokens' => $s['max_tokens'],
            'provider' => $sv['provider'] ?? 'ollama', 'url' => $sv['url'] ?? '', 'api_key' => '', 'key_set' => (bool) ($sv['key_set'] ?? false), 'key_hint' => (string) ($sv['key_hint'] ?? ''),
            'models' => implode(', ', array_map(static fn ($m) => $m['name'], $sv['models'] ?? [])),
            'url_hint' => self::URLS[$sv['provider'] ?? 'ollama'], 'model_hint' => self::MODELS[$sv['provider'] ?? 'ollama'],
            'providers' => $a['providers'],
            'status' => self::available($s),
        ];
    }

    /** 이 서버 · 모델에 쓸 기다리는 시간 (서버 값이 있으면 그것) @param array<string, mixed> $sv */
    public static function timeoutFor(array $sv, array $s): int
    {
        $t = (int) ($sv['timeout'] ?? 0);

        return $t > 0 ? max(10, $t) : (int) $s['timeout'];
    }

    /**
     * 0.4.4 🔎 모델 찾기 뒤 자동 고르기 — 지금 목록에 찾은 모델이 하나라도 있으면 그대로(null),
     * 없으면 쓸 만한 것(이름 순서: 배너 JSON 을 잘 쓰는 모델 먼저) → 그것도 없으면 첫 모델.
     *
     * @param  list<string>  $found
     * @param  list<array{name: string, enabled: bool}>  $current
     */
    public static function pickModel(array $found, array $current, string $provider = 'ollama'): ?string
    {
        $found = array_values(array_filter(array_map('strval', $found), static fn ($n) => $n !== ''));
        if ($found === []) {
            return null;
        }
        foreach ($current as $m) {
            if (in_array($m['name'] ?? '', $found, true)) {
                return null;
            }
        }
        $skip = static fn (string $n) => (bool) preg_match('/embed|whisper|tts|dall-?e|image|vision-only|moderation|rerank|audio|realtime|transcribe|search/i', $n);
        $prefs = self::PREFER[$provider] ?? [];
        foreach ($prefs as $p) {
            foreach ($found as $n) {
                if (! $skip($n) && stripos($n, $p) !== false) {
                    return $n;
                }
            }
        }
        foreach ($found as $n) {
            if (! $skip($n)) {
                return $n;
            }
        }

        return $found[0];
    }

    /** 자동 고르기 순서 (앞이 먼저) */
    public const PREFER = [
        'ollama' => ['qwen2.5:14b', 'qwen3', 'qwen2.5', 'llama3.1', 'llama3', 'gemma3', 'gemma2', 'mistral', 'phi4', 'deepseek'],
        'openai' => ['gpt-4.1', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4o-mini', 'gpt-'],
        'claude' => ['sonnet', 'opus', 'haiku', 'claude'],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini'],
        'grok' => ['grok-3', 'grok-4', 'grok'],
    ];

    /**
     * 0.4.4 화면(서버 여러 개 카드)에서 온 값 저장 — 파일을 잠그고 rev 확인 → 합치기 → 저장 (레이아웃 AI saveAi 와 같은 순서)
     *
     * @param  array<string, mixed>  $in
     *
     * @throws AiConflict 다른 창이 그 사이 저장함 · 화면이 저장 값을 못 불러옴
     */
    public static function save(array $in, bool $checkRev = true): array
    {
        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen(self::path().'.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $cur = self::load();
            $why = $checkRev ? self::conflict($in, $cur) : null;
            if ($why !== null) {
                throw new AiConflict($why, $cur);
            }
            if (! $checkRev) {
                $in['rev'] = $cur['rev'];
            }

            return self::store(self::merge($in, $cur));
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** 0.2.2 광고 모듈(custom-ad) 「AI 연결」 저장 파일 — 같은 꼴이라 그대로 가져올 수 있음 */
    public static function adPath(): string
    {
        try {
            $base = storage_path('app/modules/custom-jobs');
        } catch (\Throwable) {
            $base = sys_get_temp_dir().'/custom-jobs';
        }

        return $base.'/ai.json';
    }

    /** 광고 AI 설정이 있는지 (서버가 하나라도) */
    public static function adAvailable(): bool
    {
        $p = self::adPath();
        $j = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;

        return is_array($j) && ! empty($j['servers']);
    }

    /** 광고 AI 설정(서버 · 키 · 모델 순위 · 켜짐)을 구인구직 AI 로 복사 — 「회원도 쓰기」는 지금 값 유지 */
    public static function importFromAd(): array
    {
        $p = self::adPath();
        $j = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
        if (! is_array($j) || empty($j['servers'])) {
            throw new \RuntimeException('구인구직 AI 연결에 저장된 서버가 없어요.');
        }
        $cur = self::load();
        $in = self::normalize($j);
        $in['members'] = $cur['members'];
        $in['rev'] = $cur['rev'];

        return self::store($in);
    }

    /** 저장 파일 (홈 디자인 설정과 따로) */
    public static function path(): string
    {
        try {
            $base = storage_path('app/modules/custom-catalog');
        } catch (\Throwable) {
            $base = sys_get_temp_dir().'/custom-catalog';
        }

        return $base.'/ai.json';
    }

    /** @return array<string, mixed> 저장된 값 그대로 (키 포함 — 서버 안에서만) */
    public static function load(): array
    {
        $p = self::path();
        $j = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;

        return self::normalize(is_array($j) ? $j : []);
    }

    /** @param array<string, mixed> $s normalize 된 값 */
    public static function store(array $s): array
    {
        $s = self::normalize($s);
        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(self::path(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod(self::path(), 0640);   // API 키가 들어 있음

        return $s;
    }

    /** http(s) 주소만 (끝 / 없이) */
    public static function cleanUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return $url !== '' && preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $url) ? mb_substr($url, 0, 300) : '';
    }

    private static function bool(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }
}
