<?php

namespace Modules\Custom\Catalog\Support;

/** 0.4.4 배너 AI 설정 — 다른 창이 그 사이 저장함 (custom-home_design AiConflict 와 같음). $current = 지금 저장된 값 */
final class AiConflict extends \RuntimeException
{
    /** @param array<string, mixed> $current */
    public function __construct(string $message, public readonly array $current)
    {
        parent::__construct($message);
    }
}
