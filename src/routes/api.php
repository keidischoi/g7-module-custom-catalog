<?php

use Illuminate\Support\Facades\Route;
use Modules\Custom\Catalog\Http\Controllers\AssetController;
use Modules\Custom\Catalog\Http\Controllers\AdminController;
use Modules\Custom\Catalog\Http\Controllers\CatalogController;

Route::get('assets/{file}', [AssetController::class, 'show'])->where('file', '[A-Za-z0-9_.-]+');

Route::middleware(['throttle:120,1'])->group(function () {
    Route::get('equipment', [CatalogController::class, 'equipment']);
    Route::get('photo', [CatalogController::class, 'photo']);
    Route::get('equipment/{key}', [CatalogController::class, 'equipmentOne'])->where('key', '[a-z0-9][a-z0-9_-]{1,59}');
    Route::get('materials', [CatalogController::class, 'materials']);
    Route::get('materials/{key}', [CatalogController::class, 'materialOne'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
});

Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('admin')->group(function () {
    Route::get('equipment', [AdminController::class, 'equipment']);
    Route::post('equipment', [AdminController::class, 'saveEquipment']);
    Route::post('equipment/{key}/delete', [AdminController::class, 'remove'])->defaults('table', 'equipment');
    Route::get('materials', [AdminController::class, 'materials']);
    Route::post('materials', [AdminController::class, 'saveMaterial']);
    Route::post('materials/{key}/delete', [AdminController::class, 'remove'])->defaults('table', 'materials');
    Route::get('me', [AdminController::class, 'me']);
    Route::get('kinds', [AdminController::class, 'kinds']);
    Route::post('kinds', [AdminController::class, 'saveKinds']);
    Route::get('ai', [AdminController::class, 'ai']);
    Route::post('ai', [AdminController::class, 'saveAi']);
    Route::post('ai/suggest', [AdminController::class, 'suggest']);
});
