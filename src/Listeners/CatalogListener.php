<?php

namespace Modules\Custom\Catalog\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class CatalogListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        $spec = ['priority' => 50, 'type' => 'filter', 'sync' => true];

        return [
            'custom-home_design.home.sections' => ['method' => 'homeSections', 'priority' => 36, 'type' => 'filter'],
            'core.layout.filter_child_data' => ['method' => 'scripts'] + $spec,
            'core.layout.filter_merged' => ['method' => 'scripts'] + $spec,
            'core.layout_extension.after_apply' => ['method' => 'scripts'] + $spec,
        ];
    }

    public function handle(...$args): void {}

    public function homeSections(mixed $list = []): array
    {
        $list = is_array($list) ? array_values($list) : [];
        $list[] = [
            'key' => 'catalog',
            'title' => '3D 카탈로그',
            'subtitle' => '장비 · 필라멘트 · 레진',
            'emoji' => '🧪',
            'color' => '#b45309',
            'style' => 'cards',
            'more_url' => '/catalog',
            'order' => 49,
            'items' => [],
        ];

        return $list;
    }

    public function scripts(mixed $layout = null): mixed
    {
        if (! is_array($layout)) {
            return $layout;
        }
        $layout['scripts'] = is_array($layout['scripts'] ?? null) ? $layout['scripts'] : [];
        $src = '/api/modules/custom-catalog/assets/catalog-nav.js?v=0.1.1';
        foreach ($layout['scripts'] as $s) {
            if (is_array($s) && ($s['src'] ?? '') === $src) {
                return $layout;
            }
        }
        $layout['scripts'][] = ['src' => $src, 'defer' => true];

        return $layout;
    }
}
