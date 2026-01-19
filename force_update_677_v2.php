<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sku = 'A6190010008';
$wcService = $app->make(App\Services\WooCommerceService::class);
$wcProducts = $wcService->getProducts(['sku' => $sku]);

if (empty($wcProducts)) die("Product not found\n");
$wcProduct = $wcProducts[0];
$wcId = $wcProduct->id;

// Force Update
$data = [
    'categories' => [
        ['id' => 487],
        ['id' => 402],
        ['id' => 404],
        ['id' => 677] // TARGET
    ]
];

echo "Updating Product $wcId...\n";

try {
    // Attempt batch update since that's what the command uses
    $batch = ['update' => [array_merge(['id' => $wcId], $data)]];
    $response = $wcService->batchProducts($batch);
    
    // Parse response
    if (isset($response->update[0])) {
        $updatedProduct = $response->update[0];
        
        echo "Update Success!\n";
        echo "Categories returned by WC:\n";
        
        $found = false;
        foreach ($updatedProduct->categories as $cat) {
            echo " - ID: " . $cat->id . " (" . $cat->name . ")\n";
            if ($cat->id == 677) $found = true;
        }
        
        if ($found) {
            echo "\n✅ SUCCESS: Category 677 was accepted!\n";
        } else {
            echo "\n❌ FAILURE: Category 677 was IGNORED by WooCommerce.\n";
        }
    } else {
        echo "Response format unexpected:\n";
        print_r($response);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
