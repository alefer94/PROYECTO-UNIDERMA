<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Catalog routes
Route::get('/catalogs', [CatalogController::class, 'index'])->name('catalogs.index');
Route::post('/catalogs/sync', [CatalogController::class, 'sync'])->name('catalogs.sync');

// WooCommerce Product Sync routes
Route::get('/woocommerce/sync', function () {
    return view('woocommerce.sync');
})->name('woocommerce.sync.view');
Route::post('/woocommerce/sync-products', [ProductSyncController::class, 'syncProducts'])->name('woocommerce.sync');
Route::get('/woocommerce/products', [ProductSyncController::class, 'getWooCommerceProducts'])->name('woocommerce.products');


