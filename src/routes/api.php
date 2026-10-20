<?php

use Illuminate\Support\Facades\Route;
use Modules\Custom\Catalog\Http\Controllers\AdminController;
use Modules\Custom\Catalog\Http\Controllers\AssetController;
use Modules\Custom\Catalog\Http\Controllers\CatalogController;
use Modules\Custom\Catalog\Http\Controllers\PartsController;

Route::get('assets/{file}', [AssetController::class, 'show'])->where('file', '[A-Za-z0-9_.-]+');
Route::get('images/{file}', [CatalogController::class, 'image'])->where('file', '[A-Za-z0-9_.-]+');

Route::middleware(['throttle:180,1'])->group(function () {
    Route::get('meta', [CatalogController::class, 'meta']);
    Route::get('items', [CatalogController::class, 'items']);
    Route::get('items/{key}', [CatalogController::class, 'item'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::get('book', [CatalogController::class, 'book']);
});

// 0.3.0 🔩 부품 · 업그레이드 — 회원
Route::get('parts/printers', [PartsController::class, 'printers'])->middleware('throttle:120,1');
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('parts')->group(function () {
    Route::post('propose', [PartsController::class, 'propose'])->middleware('throttle:10,1');
    Route::post('reviews/{id}/delete', [PartsController::class, 'reviewDelete'])->whereNumber('id');
    Route::post('links/{id}/{action}', [PartsController::class, 'linkAction'])->whereNumber('id')->where('action', 'report|delete|hide|show');
    Route::post('{key}/vote', [PartsController::class, 'vote'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::post('{key}/reviews', [PartsController::class, 'review'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::post('{key}/links', [PartsController::class, 'link'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}')->middleware('throttle:20,1');
});

Route::middleware(['auth:sanctum', 'throttle:120,1'])->prefix('admin')->group(function () {
    Route::get('parts/pending', [PartsController::class, 'pending']);   // 0.3.0
    Route::post('parts/fits/{id}', [PartsController::class, 'fit'])->whereNumber('id');
    Route::post('parts/reviews/{id}/hide', [PartsController::class, 'reviewHide'])->whereNumber('id');
    Route::post('items', [AdminController::class, 'save']);
    Route::post('items/{key}/delete', [AdminController::class, 'remove'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::post('items/{key}/restore', [AdminController::class, 'restore'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::post('items/{key}/photos', [AdminController::class, 'photos'])->where('key', '[a-z0-9][a-z0-9_-]{1,79}');
    Route::post('photos/{id}/delete', [AdminController::class, 'photoDelete'])->whereNumber('id');
    Route::post('photos/{id}/main', [AdminController::class, 'photoMain'])->whereNumber('id');
    Route::get('logos', [AdminController::class, 'logos']);
    Route::post('logos', [AdminController::class, 'logoSave']);
    Route::post('logos/delete', [AdminController::class, 'logoDelete']);
    Route::post('logos/fetch', [AdminController::class, 'logoFetch'])->middleware('throttle:60,1');
    Route::get('settings', [AdminController::class, 'settings']);
    Route::post('settings', [AdminController::class, 'saveSettings']);
    Route::post('collect/run', [AdminController::class, 'collectRun'])->middleware('throttle:12,1');
    Route::get('suggestions', [AdminController::class, 'suggestions']);
    Route::post('suggestions/{id}/apply', [AdminController::class, 'suggestionApply'])->whereNumber('id');
    Route::post('suggestions/{id}/reject', [AdminController::class, 'suggestionReject'])->whereNumber('id');
    Route::get('ai', [AdminController::class, 'ai']);
    Route::post('ai', [AdminController::class, 'aiSave']);
    Route::post('ai/import-jobs', [AdminController::class, 'aiImportJobs']);
    Route::post('ai/extract', [AdminController::class, 'aiExtract'])->middleware('throttle:20,1');   // 0.2.11 AI 로 정리해 넣기
    Route::get('ai/extract/{id}', [AdminController::class, 'aiExtractJob'])->where('id', '[a-f0-9]{16}');   // 0.2.13 진행 상태
    Route::post('ai/live', [AdminController::class, 'aiLive'])->middleware('throttle:10,1');
    Route::post('ai/sample', [AdminController::class, 'aiSample'])->middleware('throttle:10,1');   // 0.2.22 🎨 실제 정리 시험
    Route::get('ai/result/{id}', [AdminController::class, 'aiResult'])->where('id', '[a-f0-9]{20}');   // 0.2.22 뒤에서 하는 시험 결과
    Route::post('ai/test', [AdminController::class, 'aiTest'])->middleware('throttle:20,1');
    Route::post('ai/models', [AdminController::class, 'aiModels'])->middleware('throttle:20,1');
});
