<?php

namespace Modules\Custom\Catalog\Support;

/**
 * 🇰🇷 0.2.22 한국어로만 · 로컬 서버 — Laravel 없이 동작 (tests 에서 직접 검사)
 *  - Qwen 같은 모델이 짧은 지시에서 중국어로 새는 것을 막음: 섞이면 한 번 더, 그래도면 그 모델은 실패(다음 순위로)
 *  - 로컬(Ollama · 집 · 회사 안 주소) 서버는 CPU 로 돌면 첫 답이 1~2분을 넘기도 해 기다리는 시간을 넉넉히 (LOCAL_MIN 초 이상)
 * 광고 0.7.10 · 홈 디자인 0.7.129 · 도우미 AI 0.1.24 · 장비 게시판 0.1.18 · 삽니다·팝니다 0.1.6 과 같은 규칙.
 */
final class AiLang
{
    public const KO_RULE = '반드시 한국어(한글)로만 답하세요. 중국어 · 일본어 · 영어 문장을 섞지 마세요 (코드 · JSON 키는 그대로).';

    public const LOCAL_MIN = 300;

    /** 중국어 한자 3자 이상 · 일본어 가나 2자 이상 (한국어 글의 한자 한두 개는 봐줌) */
    public static function foreign(string $text): bool
    {
        return preg_match_all('/\p{Han}/u', $text) >= 3 || preg_match_all('/[\x{3040}-\x{30FF}]/u', $text) >= 2;
    }

    /**
     * 한 번 더 — 앞의 답을 보여 주고 한국어로만 다시
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array{role: string, content: mixed}>
     */
    public static function retry(array $messages, string $text): array
    {
        return array_merge($messages, [['role' => 'assistant', 'content' => mb_substr($text, 0, 4000)],
            ['role' => 'user', 'content' => '방금 답에 중국어 · 일본어가 섞였어요. 같은 내용을 한국어(한글)로만 다시 써 주세요. 형식(JSON 등)은 그대로 지켜 주세요.']]);
    }

    /** 로컬 서버인가 — Ollama · vLLM 등이 사설 · 루프백 주소 · 점 없는 이름 · *.local 에 있으면 */
    public static function isLocal(string $provider, string $url): bool
    {
        $h = strtolower((string) (parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://'.$url, PHP_URL_HOST) ?? ''));
        $h = trim($h, '[]');
        if ($h === '') {
            return $provider === 'ollama';
        }
        if ($h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.lan') || str_ends_with($h, '.home.arpa') || (! str_contains($h, '.') && ! str_contains($h, ':'))) {
            return true;
        }
        if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return $h === '::1' || preg_match('/^f[cd][0-9a-f]{2}:/', $h) === 1 || str_starts_with($h, 'fe80:');
    }

    /** 이 서버에 쓸 기다리는 시간 — 로컬이면 LOCAL_MIN 이상 */
    public static function timeout(string $provider, string $url, int $setting): int
    {
        return self::isLocal($provider, $url) ? max($setting, self::LOCAL_MIN) : $setting;
    }
}
