<?php

use Illuminate\Support\Facades\Route;
use Modules\Custom\Catalog\Http\Controllers\AssetController;
use Modules\Custom\Catalog\Http\Controllers\CatalogController;

Route::get('assets/{file}', [AssetController::class, 'show'])->where('file', '[A-Za-z0-9_.-]+');

Route::middleware(['throttle:120,1'])->group(function () {
    Route::get('equipment', [CatalogController::class, 'equipment']);
    Route::get('equipment/{key}', [CatalogController::class, 'equipmentOne'])->where('key', '[a-z0-9][a-z0-9_-]{1,59}');
    Route::get('materials', [CatalogController::class, 'materials']);
    Route::get('materials/{key}', [CatalogController::class, 'materialOne'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
});
