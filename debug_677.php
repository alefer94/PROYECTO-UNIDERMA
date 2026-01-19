<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sku = 'A6190010008';
$product = App\Models\Product::where('CodCatalogo', $sku)->with([
    'laboratory', 
    'tags',
    'catalogType',
    'catalogCategory',
    'catalogSubcategory'
])->first();

if (!$product) {
    die("Product not found\n");
}

function getCatalogCategoryId($product) {
    if ($product->catalogSubcategory && $product->catalogSubcategory->WooCommerceCategoryId) {
        return $product->catalogSubcategory->WooCommerceCategoryId;
    }
    if ($product->catalogCategory && $product->catalogCategory->WooCommerceCategoryId) {
        return $product->catalogCategory->WooCommerceCategoryId;
    }
    if ($product->catalogType && $product->catalogType->WooCommerceCategoryId) {
        return $product->catalogType->WooCommerceCategoryId;
    }
    return null;
}

$categories = [];

if ($product->laboratory && $product->laboratory->WooCommerceCategoryId) {
    $categories[] = ['id' => $product->laboratory->WooCommerceCategoryId];
}

foreach ($product->tags as $tag) {
    if ($tag->WooCommerceCategoryId) {
        $categories[] = ['id' => $tag->WooCommerceCategoryId];
    }
}

$catId = getCatalogCategoryId($product);
if ($catId) {
    $categories[] = ['id' => $catId];
}

echo "PAYLOAD FOR $sku:\n";
print_r($categories);

// Also check DB values directly
echo "\nDB Values:\n";
echo "Subcategory ID in DB: " . ($product->catalogSubcategory ? $product->catalogSubcategory->WooCommerceCategoryId : 'NULL') . "\n";
echo "Subcategory Name: " . ($product->catalogSubcategory ? $product->catalogSubcategory->Nombre : 'NULL') . "\n";
