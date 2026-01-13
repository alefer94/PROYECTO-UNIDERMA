<?php

use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Rutas de sincronización
Route::post('/sync', [SyncController::class, 'sync']);
Route::post('/actions', [SyncController::class, 'executeAction']);
