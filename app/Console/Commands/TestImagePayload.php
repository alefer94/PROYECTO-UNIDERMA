<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageService;
use App\Services\ProductSyncService;
use App\Services\WooCommerceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestImagePayload extends Command
{
    protected $signature = 'debug:image-payload {sku}';

    protected $description = 'Debug image payload generation and WC stored data';

    public function handle(ProductSyncService $syncService, ProductImageService $imageService, WooCommerceService $wcService)
    {
        $sku = $this->argument('sku');
        $this->info("Debugging Product SKU: $sku");

        $product = Product::where('CodCatalogo', $sku)->first();

        if (! $product) {
            $this->error('Product not found!');

            return;
        }

        $this->info("Product found: {$product->Nombre}");
        $this->info("Home path: {$product->Home}");

        // 1. Test getWooCommerceImageUrls
        $this->info("\n--- Testing getWooCommerceImageUrls ---");
        $urls = $imageService->getWooCommerceImageUrls($product->Home);

        $data = collect($urls)->map(function ($img, $index) {
            $exists = false;
            $contentType = 'N/A';
            $singleExtUrl = '';
            $singleExtExists = false;

            try {
                $response = Http::timeout(5)->head($img['src']);
                $exists = $response->successful();
                $contentType = $response->header('Content-Type');
            } catch (\Exception $e) {
            }

            // Check single extension version if double extension detected
            if (preg_match('/\.jpg\.jpg$/i', $img['src'])) {
                $singleExtUrl = preg_replace('/\.jpg\.jpg$/i', '.jpg', $img['src']);
                try {
                    $singleExtExists = Http::timeout(5)->head($singleExtUrl)->successful();
                } catch (\Exception $e) {
                }
            }

            return [
                $index,
                $img['src'],
                $exists ? "YES ($contentType)" : 'NO',
                $singleExtUrl ? ($singleExtExists ? "YES ($singleExtUrl)" : "NO ($singleExtUrl)") : 'N/A',
            ];
        });

        $this->table(
            ['Index', 'URL', 'Exists (HEAD)', 'Single Ext Check'],
            $data
        );

        // 2. Test mapProductToWooCommerce
        $this->info("\n--- Testing mapProductToWooCommerce Payload ---");
        $payload = $syncService->mapProductToWooCommerce($product);
        if (isset($payload['images'])) {
            $this->info("Payload 'images' key:");
            dump($payload['images']);
        } else {
            $this->warn("Payload has NO 'images' key!");
        }

        // 3. Test WooCommerce Stored Data (How it received it)
        $this->info("\n--- Test WooCommerce Stored Data (How it received it) ---");
        try {
            $wcProducts = $wcService->getProducts(['sku' => $sku]);
            if (! empty($wcProducts)) {
                $wcProduct = $wcProducts[0];
                $this->info("WooCommerce Product Found: ID {$wcProduct->id}");
                if (! empty($wcProduct->images)) {
                    $this->info('Stored Images:');
                    dump($wcProduct->images);
                } else {
                    $this->warn('WooCommerce Product has NO images.');
                }
            } else {
                $this->error('Product not found in WooCommerce!');
            }
        } catch (\Exception $e) {
            $this->error('Failed to fetch from WooCommerce: '.$e->getMessage());
        }
    }
}
