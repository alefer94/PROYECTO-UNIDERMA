<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Catalog routes
Route::get('/catalogs', [CatalogController::class, 'index'])->name('catalogs.index');
Route::post('/catalogs/sync', [CatalogController::class, 'sync'])->name('catalogs.sync');
