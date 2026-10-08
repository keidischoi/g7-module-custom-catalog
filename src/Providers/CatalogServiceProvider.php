<?php

namespace Modules\Custom\Catalog\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Custom\Catalog\Console\CollectCommand;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CollectCommand::class]);
        }
    }
}
