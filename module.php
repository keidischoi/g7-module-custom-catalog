<?php

namespace Modules\Custom\Catalog;

use App\Extension\AbstractModule;
use Modules\Custom\Catalog\Listeners\CatalogListener;

class Module extends AbstractModule
{
    public function getDependencies(): array
    {
        return ['modules' => []];
    }

    public function getAdminMenus(): array
    {
        return [[
            'name' => ['ko' => '3D 카탈로그', 'en' => '3D Catalog'],
            'slug' => 'custom-catalog',
            'url' => '/admin/catalog',
            'icon' => 'fas fa-cube',
            'order' => 42,
            'children' => [[
                'name' => ['ko' => '제원 입력', 'en' => 'Specs'],
                'slug' => 'custom-catalog-edit',
                'url' => '/admin/catalog',
                'order' => 10,
            ]],
        ]];
    }

    public function getHookListeners(): array
    {
        return [CatalogListener::class];
    }
}
