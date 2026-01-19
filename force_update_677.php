<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sku = 'A6190010008'; // NOVEXPERT LIP UP X 8ML
$product = App\Models\Product::where('CodCatalogo', $sku)->first();

if (!$product) die("Product not found");

// Get the WC ID
$wcService = $app->make(App\Services\WooCommerceService::class);
$wcProducts = $wcService->getProducts(['sku' => $sku]);

if (empty($wcProducts)) die("Product not found in WooCommerce via SKU");

$wcProduct = $wcProducts[0];
$wcId = $wcProduct->id;

echo "Found WC Product ID: $wcId\n";
echo "Current WC Categories: " . json_encode($wcProduct->categories) . "\n\n";

// Construct Payload
$categories = [];
// Hardcoded based on our previous debug findings to be 100% sure
$categories[] = ['id' => 487];
$categories[] = ['id' => 402];
$categories[] = ['id' => 404];
$categories[] = ['id' => 677]; // The missing one

$data = [
    'categories' => $categories
];

echo "Attempting to update with payload:\n";
print_r($data);

try {
    // We use the 'updateProduct' method if it exists, or raw request
    // Looking at SyncWooCommerceProducts, it uses batch, but the service likely has updateProduct or put
    // We will try to use the underlying client to be raw
    
    // Check if updateProduct exists in service
    if (method_exists($wcService, 'updateProduct')) {
        echo "Using updateProduct method...\n";
        $response = $wcService->updateProduct($wcId, $data);
    } else {
        // Fallback to direct client usage if possible, or assume a generic 'update' method exists
        // Let's assume we can use the batch endpoint with a single item if no direct method, 
        // as that's what the command uses.
        echo "Using batchProducts method (simulating single update)...\n";
        $batch = [
            'update' => [
                array_merge(['id' => $wcId], $data)
            ]
        ];
        $response = $wcService->batchProducts($batch);
    }
    
    echo "\nRESPONSE:\n";
    print_r($response);
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse')) {
        echo "Response Body: " . $e->getResponse()->getBody()->getContents() . "\n";
    }
}
