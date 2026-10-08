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

    public function getHookListeners(): array
    {
        return [CatalogListener::class];
    }
}
