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
                'name' => ['ko' => '항목 관리', 'en' => 'Items'],
                'slug' => 'custom-catalog-edit',
                'url' => '/admin/catalog',
                'order' => 10,
                'permission' => 'custom-catalog.specs.update',
            ], [
                'name' => ['ko' => '설정', 'en' => 'Settings'],
                'slug' => 'custom-catalog-settings',
                'url' => '/admin/catalog/settings',
                'order' => 20,
                'permission' => 'custom-catalog.specs.update',
            ]],
        ]];
    }

    public function getHookListeners(): array
    {
        return [CatalogListener::class];
    }

    public function getDynamicTables(): array
    {
        return ['cat_equipment', 'cat_materials', 'cat_barcodes', 'cat_photos', 'cat_suggestions'];
    }

    /** 0.2.0 🌙 조용할 때 자동 수집 — 10분마다 불러 보고, 설정한 시간 · 부하가 아니면 바로 끝남 */
    public function getSchedules(): array
    {
        return [
            ['command' => 'catalog:collect', 'schedule' => '*/10 * * * *', 'description' => '3D 카탈로그 — 조용할 때 AI 로 새 모델 · 제원 · 사진 찾기'],
        ];
    }
}
