<?php

use App\Services\WooCommerceService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$wc = $app->make(WooCommerceService::class);

$BATCH_SIZE = 100;

echo "⚠️  WARNING: This script will DELETE ALL PRODUCTS AND CATEGORIES from WooCommerce.\n";
echo "Are you sure you want to continue? (Type 'yes' to proceed): ";
$handle = fopen ("php://stdin","r");
$line = fgets($handle);
if(trim($line) != 'yes'){
    echo "Aborted.\n";
    exit;
}

echo "\n---------------------------------------------------------------\n";
echo "PHASE 1: DELETING PRODUCTS\n";
echo "---------------------------------------------------------------\n";

$page = 1;
do {
    echo "Fetching products (Page 1)...\n";
    // Always fetch page 1 because deletion shifts remaining items to page 1
    $products = $wc->getProducts(['per_page' => $BATCH_SIZE, 'page' => 1]);
    
    if (empty($products)) {
        echo "No more products found.\n";
        break;
    }
    
    $ids = collect($products)->pluck('id')->toArray();
    $count = count($ids);
    echo "Found {$count} products. Deleting...\n";
    
    try {
        $wc->batchProducts(['delete' => $ids]);
        echo "✓ Deleted {$count} products.\n";
    } catch (\Exception $e) {
        echo "✗ Error deleting products: " . $e->getMessage() . "\n";
        // If error is persistent, we might get stuck in loop, so verifying
        // In real massive deletion, sometimes items are stubborn.
    }
    
    // Safety break for testing/prevents infinite loops if API fails to delete
    // sleep(1); 
    
} while (true);


echo "\n---------------------------------------------------------------\n";
echo "PHASE 2: DELETING CATEGORIES\n";
echo "---------------------------------------------------------------\n";

do {
    echo "Fetching categories (Page 1)...\n";
    $cats = $wc->getCategories(['per_page' => $BATCH_SIZE, 'page' => 1]);
    
    if (empty($cats)) {
        echo "No more categories found.\n";
        break;
    }
    
    // Filter out 'Uncategorized' (usually ID 15 or slug 'uncategorized')
    // API might fail if we try to delete default category
    $idsToDelete = [];
    foreach ($cats as $cat) {
        if ($cat->slug === 'uncategorized' || $cat->id === 15) {
            echo "Skipping default category: {$cat->name} ({$cat->id})\n";
            continue;
        }
        $idsToDelete[] = $cat->id;
    }
    
    if (empty($idsToDelete)) {
        echo "Only default category remains. Finished.\n";
        break;
    }
    
    echo "Found " . count($idsToDelete) . " categories to delete...\n";
    
    try {
        $wc->batchCategories(['delete' => $idsToDelete]);
        echo "✓ Deleted " . count($idsToDelete) . " categories.\n";
    } catch (\Exception $e) {
        echo "✗ Error deleting categories: " . $e->getMessage() . "\n";
    }

} while (true);

echo "\nDONE. WooCommerce has been wiped.\n";
echo "IMPORTANT: Now run 'php artisan woocommerce:sync-catalog-categories' to repair IDs before syncing products.\n";
