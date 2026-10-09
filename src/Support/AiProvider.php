<?php

namespace Modules\Custom\Catalog\Support;

/**
 * custom-jobs 0.2.0: custom-ad 의 같은 이름 클래스를 그대로 옮김 (광고 AI 연결과 따로 저장 — storage/app/modules/custom-jobs/ai.json).
 * 🔌 AI 서버 종류별 요청 만들기 · 답 읽기 — custom-home_design 0.7.37 HomeAiProvider 를 그대로 옮김 (0.4.3). 보내는 일은 BannerAiService
 *
 *  - claude : {base}/messages (system 따로 · x-api-key · anthropic-version)
 *  - openai · grok · gemini : OpenAI 호환 {base}/chat/completions (gemini 는 …/v1beta/openai)
 *  - ollama : {url}/api/chat
 * 배너 작업(ops) JSON 도 길 수 있어 답을 줄이지 않음. 순수 PHP.
 */
final class AiProvider
{
    public static function family(string $provider): string
    {
        return match ($provider) {
            'openai', 'grok', 'gemini' => 'openai',
            'claude' => 'claude',
            default => 'ollama',
        };
    }

    public static function apiBase(string $provider, string $url): string
    {
        $url = rtrim($url, '/');

        return match ($provider) {
            'gemini' => preg_match('#/openai$#', $url) ? $url : (preg_match('#/v\d+(beta\d*|alpha\d*)?$#', $url) ? $url.'/openai' : $url.'/v1beta/openai'),
            'openai', 'grok', 'claude' => preg_match('#/v\d+$#', $url) ? $url : $url.'/v1',
            default => $url,
        };
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages  system · user
     * @return array{url: string, headers: array<string, string>, body: array<string, mixed>, family: string}
     */
    /** @param array{temperature?: float} $opt 0.2.17 물음마다 바꿀 것 (정리하기는 0 — 지어내지 않게) */
    public static function buildRequest(string $provider, string $url, string $key, string $model, array $messages, int $maxTokens, bool $json = true, array $images = [], array $opt = []): array
    {
        $temp = isset($opt['temperature']) ? (float) $opt['temperature'] : 0.4;
        $family = self::family($provider);
        $base = self::apiBase($provider, $url);
        // custom-jobs 0.2.0: 사진 읽기(OCR) — 마지막 user 메시지에 그림을 붙임 [{mime, data(base64)}]
        $images = array_values(array_filter($images, static fn ($i) => is_array($i) && ! empty($i['data']) && preg_match('#^image/(png|jpe?g|webp|gif)$#', (string) ($i['mime'] ?? ''))));
        if ($images !== []) {
            return self::visionRequest($family, $provider, $base, $key, $model, $messages, $maxTokens, $images);
        }
        if ($family === 'claude') {
            $system = [];
            $user = [];
            foreach ($messages as $m) {
                $c = trim((string) ($m['content'] ?? ''));
                if ($c === '') {
                    continue;
                }
                if (($m['role'] ?? '') === 'system') {
                    $system[] = $c;
                } else {
                    $user[] = $c;
                }
            }
            $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => implode("\n\n", $user ?: ['(요청 없음)'])]]];
            if ($system) {
                $body['system'] = implode("\n\n", $system);
            }
            $headers = array_filter(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']);

            return ['url' => $base.'/messages', 'headers' => $headers, 'body' => $body, 'family' => $family];
        }
        $headers = $key !== '' ? ['Authorization' => 'Bearer '.$key] : [];
        if ($family === 'openai') {
            $body = ['model' => $model, 'stream' => false, 'messages' => array_values($messages), 'max_tokens' => $maxTokens, 'temperature' => $temp];
            if ($json && $provider === 'openai') {
                $body['response_format'] = ['type' => 'json_object'];
            }

            return ['url' => $base.'/chat/completions', 'headers' => $headers, 'body' => $body, 'family' => $family];
        }
        $body = ['model' => $model, 'stream' => false, 'messages' => array_values($messages), 'keep_alive' => '15m',
            'options' => ['temperature' => $temp, 'num_predict' => $maxTokens, 'num_ctx' => self::ctxFor($messages, $maxTokens)]];
        if ($json) {
            $body['format'] = 'json';
        }

        return ['url' => $base.'/api/chat', 'headers' => $headers, 'body' => $body, 'family' => $family];
    }

    /**
     * 0.2.17 Ollama 문맥 크기를 물음 길이에 맞춤 (4K · 8K · 16K · 32K) — 늘 32K 를 잡으면 CPU 에서는 작은 모델도 몇 분씩 걸림.
     * 한글은 대략 글자 하나가 토큰 하나, 영어 · 숫자는 그보다 적음 → 글자 수로 넉넉히 셈.
     *
     * @param  list<array{role?: string, content?: string}>  $messages
     */
    public static function ctxFor(array $messages, int $maxTokens): int
    {
        $chars = 0;
        foreach ($messages as $m) {
            $chars += mb_strlen((string) ($m['content'] ?? ''));
        }
        $need = (int) ceil($chars * 1.1) + min($maxTokens, 4096) + 256;
        foreach ([4096, 8192, 16384] as $c) {
            if ($need <= $c) {
                return $c;
            }
        }

        return 32768;
    }

    /**
     * custom-jobs 0.2.0: 그림이 있는 요청
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array{mime: string, data: string}>  $images
     * @return array{url: string, headers: array<string, string>, body: array<string, mixed>, family: string}
     */
    private static function visionRequest(string $family, string $provider, string $base, string $key, string $model, array $messages, int $maxTokens, array $images): array
    {
        $system = [];
        $user = [];
        foreach ($messages as $m) {
            $c = trim((string) ($m['content'] ?? ''));
            if ($c !== '') {
                (($m['role'] ?? '') === 'system') ? $system[] = $c : $user[] = $c;
            }
        }
        $text = implode("\n\n", $user ?: ['그림을 읽어 줘.']);
        if ($family === 'claude') {
            $content = [];
            foreach ($images as $i) {
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $i['mime'], 'data' => $i['data']]];
            }
            $content[] = ['type' => 'text', 'text' => $text];
            $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $content]]];
            if ($system) {
                $body['system'] = implode("\n\n", $system);
            }

            return ['url' => $base.'/messages', 'headers' => array_filter(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']), 'body' => $body, 'family' => $family];
        }
        $headers = $key !== '' ? ['Authorization' => 'Bearer '.$key] : [];
        if ($family === 'openai') {
            $content = [['type' => 'text', 'text' => $text]];
            foreach ($images as $i) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$i['mime'].';base64,'.$i['data']]];
            }
            $msgs = $system ? [['role' => 'system', 'content' => implode("\n\n", $system)]] : [];
            $msgs[] = ['role' => 'user', 'content' => $content];

            return ['url' => $base.'/chat/completions', 'headers' => $headers, 'body' => ['model' => $model, 'stream' => false, 'messages' => $msgs, 'max_tokens' => $maxTokens, 'temperature' => 0.2], 'family' => $family];
        }
        $msgs = $system ? [['role' => 'system', 'content' => implode("\n\n", $system)]] : [];
        $msgs[] = ['role' => 'user', 'content' => $text, 'images' => array_map(static fn ($i) => $i['data'], $images)];

        return ['url' => $base.'/api/chat', 'headers' => $headers, 'body' => ['model' => $model, 'stream' => false, 'messages' => $msgs, 'format' => 'json', 'keep_alive' => '15m',
            // 0.9.37 사진 요청은 문맥 크기를 8192 로 — 32768 이면 그림 모델(llava · qwen2.5vl …)이 메모리를 너무 많이 잡아 Ollama 가 연결을 끊었음 (cURL 52 · 56)
            'options' => ['temperature' => 0.2, 'num_predict' => $maxTokens, 'num_ctx' => 8192]], 'family' => $family];
    }

    /** 0.6.0 모델 목록 요청 (모델 찾기) @return array{url: string, headers: array<string, string>} */
    public static function modelsRequest(string $provider, string $url, string $key): array
    {
        $family = self::family($provider);
        $base = self::apiBase($provider, $url);
        if ($family === 'claude') {
            return ['url' => $base.'/models?limit=100', 'headers' => array_filter(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])];
        }

        return ['url' => $family === 'openai' ? $base.'/models' : $base.'/api/tags', 'headers' => $key !== '' ? ['Authorization' => 'Bearer '.$key] : []];
    }

    /** @return list<string> 모델 이름 */
    public static function parseModels(string $provider, array $json): array
    {
        $list = self::family($provider) === 'ollama' ? ($json['models'] ?? []) : ($json['data'] ?? []);
        $out = [];
        foreach (is_array($list) ? $list : [] as $m) {
            $n = is_array($m) ? (string) ($m['name'] ?? $m['id'] ?? '') : '';
            if ($n !== '') {
                $out[] = (string) preg_replace('#^models/#', '', $n);
            }
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    public static function parseReply(string $provider, array $json): string
    {
        $text = match (self::family($provider)) {
            'openai' => (string) ($json['choices'][0]['message']['content'] ?? ''),
            'claude' => implode('', array_map(
                static fn ($b) => is_array($b) && ($b['type'] ?? '') === 'text' ? (string) ($b['text'] ?? '') : '',
                is_array($json['content'] ?? null) ? $json['content'] : []
            )),
            default => (string) ($json['message']['content'] ?? ''),
        };

        return trim(preg_replace('#<think>.*?</think>#su', '', $text) ?? $text);
    }

    /** 오류 응답에서 사람이 읽을 말 */
    public static function errorText(mixed $json, string $raw): string
    {
        $e = is_array($json) ? ($json['error']['message'] ?? $json[0]['error']['message'] ?? $json['error'] ?? null) : null;

        return mb_substr(is_string($e) && $e !== '' ? $e : $raw, 0, 200);
    }
}
