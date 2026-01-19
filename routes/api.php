<?php

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\ProductImageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Rutas de sincronización
Route::post('/sync', [SyncController::class, 'sync']);
Route::post('/actions', [SyncController::class, 'executeAction']);

// Rutas de imágenes de productos (con rate limiting)
Route::middleware('throttle:100,1')->group(function () {
    Route::get('/product-images/{code}/{filename}', [ProductImageController::class, 'show'])
        ->where('code', '[A-Za-z0-9]+')
        ->where('filename', '[A-Za-z0-9._-]+');
});
