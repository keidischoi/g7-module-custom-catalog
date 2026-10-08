<?php

namespace Modules\Custom\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Custom\Catalog\Services\Collector;

/** 0.2.0 🌙 자동 수집 한 번 — 스케줄이 10분마다 부름 (조용하지 않으면 바로 끝). --force 면 조건 무시 */
class CollectCommand extends Command
{
    protected $signature = 'catalog:collect {--force : 시간 · 부하 조건을 보지 않고 한 번} {--task= : members | new | fill | photo | sds 하나만}';

    protected $description = '3D 카탈로그 — 조용할 때 AI 로 새 모델 · 제원 · 사진 · 안전 자료(MSDS) 찾기';

    public function handle(Collector $collector): int
    {
        $task = in_array($this->option('task'), Collector::TASKS, true) ? (string) $this->option('task') : null;
        $res = $collector->tick((bool) $this->option('force') || $task !== null, $task);
        foreach ($res['ran'] as $line) {
            $this->line($line);
        }
        if ($res['skipped'] !== '') {
            $this->line('쉬었어요: '.$res['skipped']);
        }

        return self::SUCCESS;
    }
}
