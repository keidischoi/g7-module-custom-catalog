<?php

namespace Modules\Custom\Catalog\Services;

use Modules\Custom\Catalog\Support\AiProvider;
use Modules\Custom\Catalog\Support\AiSettings;

/**
 * 0.2.0 AI 에게 묻기 — 구인구직(custom-jobs) JobsAiService 와 같은 방식:
 * 설정한 서버 · 모델 순위대로 묻고, 안 되면 다음 모델 → 다음 서버로.
 */
final class AiClient
{
    public const SYSTEM = '너는 3D 프린터 · 3D 장비 · 필라멘트 · 레진 제품 자료를 정리하는 도우미다. 제조사가 공개한 사실만 쓴다. 모르거나 확실하지 않은 값은 null 로 둔다 — 절대 지어내지 않는다. 답은 JSON 하나로만 한다 (설명 · 코드 울타리 없이).';

    public const TEST_SYSTEM = '짧게 답한다. 한국어(한글)로만 답한다.';

    /** 테스트용: fn(string $url, array $headers, array $body, int $timeout): array */
    public static $sender = null;

    public static ?array $settingsOverride = null;

    /** 테스트용: fn(string $url, array $headers): array */
    public static $getter = null;

    /** 마지막으로 답한 곳 「서버 · 모델」 */
    public string $last = '';

    /** 0.2.17 물음 설정 — temperature(정리하기 0) · max_tokens(답 길이 한도). 비우면 설정 그대로 */
    public array $opt = [];

    /** 0.2.14 물어보기 직전에 부름: fn(string $server, string $model, int $timeout, list<string> $errors) — 진행 상태 보이기 */
    public $onTry = null;

    public function settings(): array
    {
        if (self::$settingsOverride !== null) {
            return AiSettings::normalize(self::$settingsOverride);
        }
        try {
            return AiSettings::load();
        } catch (\Throwable) {
            return AiSettings::normalize([]);
        }
    }

    /** @return array{enabled: bool, reason: string} */
    public function available(): array
    {
        return AiSettings::available($this->settings());
    }

    /**
     * JSON 으로 답 받기
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function json(string $prompt): array
    {
        $s = $this->settings();
        $can = AiSettings::available($s);
        if (! $can['enabled']) {
            throw new \RuntimeException($can['reason']);
        }
        $errors = [];
        foreach (AiSettings::order($s, false) as $o) {
            if (is_callable($this->onTry)) {
                ($this->onTry)((string) $o['server']['name'], (string) $o['model'], (int) AiSettings::timeoutFor($o['server'], $s), $errors);
            }
            try {
                $text = $this->ask($o['server'], $o['model'], [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $prompt]],
                    isset($this->opt['max_tokens']) ? min((int) $s['max_tokens'], (int) $this->opt['max_tokens']) : $s['max_tokens'], AiSettings::timeoutFor($o['server'], $s), true);
                $j = self::parseJson($text);
                if ($j === null) {
                    throw new \RuntimeException('JSON 이 아닌 답');
                }
                $this->last = $o['server']['name'].' · '.$o['model'];

                return $j;
            } catch (\Throwable $e) {
                $errors[] = $o['server']['name'].' · '.$o['model'].': '.$e->getMessage();
            }
        }

        throw new \RuntimeException(implode(' / ', array_slice($errors, 0, 3)) ?: '물어볼 서버가 없습니다.');
    }

    public function test(array $server, string $model, int $timeout = 25): array
    {
        $model = trim($model) !== '' ? trim($model) : (AiSettings::enabledModels($server)[0] ?? AiSettings::MODELS[$server['provider']]);
        $base = ['model' => $model, 'url' => AiSettings::url($server), 'server' => (string) $server['name']];
        if ($server['provider'] !== 'ollama' && $server['api_key'] === '') {
            return $base + ['ok' => false, 'message' => '❌ API 키가 없습니다.', 'answer' => '', 'sec' => 0.0];
        }
        $t0 = microtime(true);
        try {
            $answer = $this->ask($server, $model, [['role' => 'system', 'content' => self::TEST_SYSTEM], ['role' => 'user', 'content' => '「연결 성공」이라고만 답해 줘.']], 60, max(5, min(25, $timeout)), false);
        } catch (\Throwable $e) {
            return $base + ['ok' => false, 'message' => '❌ '.$e->getMessage(), 'answer' => '', 'sec' => round(microtime(true) - $t0, 1)];
        }
        $sec = round(microtime(true) - $t0, 1);

        return $base + ['ok' => true, 'message' => '✅ '.$model.' — '.$sec.'초 만에 답했어요.', 'answer' => mb_substr($answer, 0, 80), 'sec' => $sec];
    }

    public function models(array $server): array
    {
        $req = AiProvider::modelsRequest($server['provider'], AiSettings::url($server), (string) $server['api_key']);
        try {
            if (is_callable(self::$getter)) {
                $json = (array) (self::$getter)($req['url'], $req['headers']);
            } else {
                $res = \Illuminate\Support\Facades\Http::acceptJson()->connectTimeout(5)->timeout(15)->withHeaders($req['headers'])->get($req['url']);
                if (! $res->successful()) {
                    return ['models' => [], 'message' => '서버가 오류로 답함 (HTTP '.$res->status().') '.AiProvider::errorText($res->json(), (string) $res->body())];
                }
                $json = (array) $res->json();
            }
        } catch (\Throwable $e) {
            return ['models' => [], 'message' => mb_substr($e->getMessage(), 0, 200)];
        }
        $list = AiProvider::parseModels($server['provider'], $json);

        return ['models' => $list, 'message' => $list ? '모델 '.count($list).'개를 찾았어요.' : '모델 목록이 비었어요 (주소 · 키 확인, 이름을 직접 적어도 돼요).'];
    }

    private function ask(array $server, string $model, array $messages, int $maxTokens, int $timeout, bool $json): string
    {
        $url = AiSettings::url($server);
        $req = AiProvider::buildRequest($server['provider'], $url, (string) $server['api_key'], $model, $messages, $maxTokens, $json, [], $this->opt);
        try {
            $res = $this->send($req['url'], $req['headers'], $req['body'], $timeout);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (preg_match('/timed out|timeout/i', $m)) {
                throw new \RuntimeException('응답 시간 초과 ('.$timeout.'초)');
            }
            if (preg_match('/empty reply from server|recv failure|connection reset|cURL error (52|56)/i', $m)) {
                throw new \RuntimeException('AI 서버가 답하다가 연결을 끊었어요 (메모리가 모자랐을 수 있어요 — 더 작은 모델을 써 보세요)');
            }
            if (preg_match("/could not resolve|failed to connect|connection refused|couldn't connect|no route to host|getaddrinfo/i", $m)) {
                throw new \RuntimeException('서버에 연결할 수 없습니다 ('.$url.')');
            }
            throw new \RuntimeException(mb_substr($m, 0, 200));
        }
        $text = AiProvider::parseReply($server['provider'], $res);
        if ($text === '') {
            throw new \RuntimeException('빈 답이 왔습니다 ('.$model.').');
        }

        return $text;
    }

    /** 답에서 JSON 하나 꺼내기 (```json``` · 생각 과정 · 앞뒤 말 무시) */
    public static function parseJson(string $text): ?array
    {
        $text = trim(preg_replace('#<think>.*?</think>#su', '', $text) ?? $text);
        if (preg_match('/```(?:json)?\s*([\{\[].*[\}\]])\s*```/su', $text, $m)) {
            $text = $m[1];
        }
        $j = json_decode($text, true);
        if (is_array($j)) {
            return $j;
        }
        foreach ([['{', '}'], ['[', ']']] as [$a, $b]) {
            $start = strpos($text, $a);
            $end = strrpos($text, $b);
            if ($start !== false && $end !== false && $end > $start) {
                $cut = substr($text, $start, $end - $start + 1);
                $j = json_decode($cut, true) ?? json_decode(preg_replace('/[\x00-\x1F]+/', ' ', $cut) ?? '', true);
                if (is_array($j)) {
                    return $j;
                }
            }
        }

        return null;
    }

    private function send(string $url, array $headers, array $body, int $timeout): array
    {
        if (is_callable(self::$sender)) {
            return (array) (self::$sender)($url, $headers, $body, $timeout);
        }
        $res = \Illuminate\Support\Facades\Http::acceptJson()->asJson()->connectTimeout(8)->timeout(max(10, $timeout))->withHeaders($headers)->post($url, $body);
        if (! $res->successful()) {
            throw new \RuntimeException('서버가 오류로 답함 (HTTP '.$res->status().') '.AiProvider::errorText($res->json(), (string) $res->body()));
        }

        return (array) $res->json();
    }
}
