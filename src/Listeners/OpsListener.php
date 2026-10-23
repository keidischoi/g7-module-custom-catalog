<?php

namespace Modules\Custom\Catalog\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Custom\Catalog\Support\OpsSource;

/**
 * 0.3.13 🖥️ 3DS 관리 프로그램(custom-ops)에 💡 제안함 · 🔩 부품 맞음 확인 · 📋 항목 목록을 더함 (필터 custom-ops.sources).
 * 그 플러그인이 없으면 이 필터는 불리지 않음.
 */
class OpsListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'custom-ops.sources' => ['method' => 'register', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    /** @return array<string, object> */
    public function register(mixed $list = []): array
    {
        $list = is_array($list) ? $list : [];
        $list['catalog'] ??= new OpsSource;

        return $list;
    }
}
