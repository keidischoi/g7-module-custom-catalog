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

    public function getRoles(): array
    {
        return [[
            'identifier' => 'custom-catalog.editor',
            'name' => ['ko' => '카탈로그 편집', 'en' => 'Catalog editor'],
            'description' => ['ko' => '장비 · 필라멘트 제원 입력', 'en' => 'Enter equipment and filament specs'],
        ]];
    }

    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '3D 카탈로그', 'en' => '3D Catalog'],
            'description' => ['ko' => '제원 입력 권한', 'en' => 'Catalog spec permission'],
            'categories' => [[
                'identifier' => 'specs',
                'name' => ['ko' => '제원', 'en' => 'Specs'],
                'description' => ['ko' => '장비 · 재료 카드 입력', 'en' => 'Edit catalog cards'],
                'type' => 'admin',
                'permissions' => [[
                    'action' => 'update',
                    'name' => ['ko' => '제원 입력', 'en' => 'Edit specs'],
                    'description' => ['ko' => '장비 · 필라멘트 · 레진 제원 입력', 'en' => 'Edit specs'],
                    'type' => 'admin',
                    'roles' => ['admin', 'custom-catalog.editor'],
                ]],
            ]],
        ];
    }

    public function getAdminMenus(): array
    {
        return [[
            'name' => ['ko' => '3D 카탈로그', 'en' => '3D Catalog'],
            'slug' => 'custom-catalog',
            'url' => '/admin/catalog',
            'icon' => 'fas fa-cube',
            'order' => 42,
            'permission' => 'custom-catalog.specs.update',
            'children' => [[
                'name' => ['ko' => '제원 입력', 'en' => 'Specs'],
                'slug' => 'custom-catalog-edit',
                'url' => '/admin/catalog',
                'order' => 10,
                'permission' => 'custom-catalog.specs.update',
            ]],
        ]];
    }

    public function getHookListeners(): array
    {
        return [CatalogListener::class];
    }
}
