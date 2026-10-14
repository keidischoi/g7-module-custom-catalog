<?php

namespace Modules\Custom\Catalog\Support;

use Illuminate\Support\Facades\Cache;

/**
 * ⏳ 0.2.22 AI 연결 시험을 뒤에서 (광고 0.7.10 과 같음)
 *  작은 CPU 로컬 서버(Ollama)는 답이 1분을 넘기도 해서, 한 번에 기다리면 PHP 시간 제한 · NAS 프록시(60초)가 연결을 끊음.
 *  → 응답(「시작」 + id)을 먼저 보내고(terminating) 그 뒤에 묻기 · 결과는 캐시에 30분 · 화면이 GET admin/ai/result/{id} 로 2초마다 물어봄.
 *  결과는 시작한 사람만 볼 수 있음.
 */
final class AiJobs
{
    public const TTL = 1800;

    /**
     * @param  \Closure(): array<string, mixed>  $work  실패하면 예외 (InvalidArgumentException 은 요청 문제)
     * @return array{id: string, state: string}
     */
    public static function start(string $kind, int $user, \Closure $work): array
    {
        $id = bin2hex(random_bytes(10));
        $key = 'cct:aijob:'.$id;
        Cache::put($key, ['state' => 'run', 'kind' => $kind, 'at' => time(), 'user' => $user], self::TTL);
        $job = static function () use ($key, $kind, $user, $work): void {
            @set_time_limit(960);
            @ignore_user_abort(true);
            $t = microtime(true);
            try {
                $out = ['state' => 'done', 'ok' => true, 'data' => $work()];
            } catch (\Throwable $e) {
                $out = ['state' => 'done', 'ok' => false, 'message' => mb_substr($e->getMessage(), 0, 400)];
            }
            Cache::put($key, $out + ['kind' => $kind, 'user' => $user, 'sec' => round(microtime(true) - $t, 1)], self::TTL);
        };
        if (method_exists(app(), 'terminating') && ! app()->runningInConsole()) {
            app()->terminating($job);
        } else {
            $job();
        }

        return ['id' => $id, 'state' => 'run'];
    }

    /** @return array<string, mixed>|null {state: run, sec} · {state: done, ok, data | message} — 없거나 다른 사람 것이면 null */
    public static function get(string $id, int $user): ?array
    {
        $r = preg_match('/^[a-f0-9]{20}$/', $id) ? Cache::get('cct:aijob:'.$id) : null;
        if (! is_array($r) || (int) ($r['user'] ?? 0) !== $user || $user < 1) {
            return null;
        }
        if (($r['state'] ?? '') === 'run') {
            $r['sec'] = time() - (int) ($r['at'] ?? time());
        }
        unset($r['user']);

        return $r;
    }
}
