<?php

namespace Modules\Custom\Catalog;

use App\Extension\AbstractModule;

/** 3D 카탈로그 — 장비 · 재료 카드의 주인. 다른 모듈은 표를 직접 쓰지 않고 key 로 조회 */
class Module extends AbstractModule
{
    public function getDependencies(): array
    {
        return ['modules' => []];
    }
}
