<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Services\ProductImageService;
use Illuminate\Support\Facades\Storage;

class TestImageSync extends Command
{
    protected $signature = 'debug:image-sync {sku}';
    protected $description = 'Debug image sync for a product';

    public function handle(ProductImageService $service)
    {
        $sku = $this->argument('sku');
        $this->info("Debugging SKU: $sku");

        $product = Product::where('CodCatalogo', $sku)->first();

        if (!$product) {
            $this->error("Product not found in DB");
            return;
        }

        $this->info("DB Home value: " . ($product->Home ?? 'NULL'));
        
        $basePath = storage_path('app/public/ftp_sync');
        $this->info("Base Path: $basePath");
        $this->info("Directory exists? " . (is_dir($basePath) ? 'YES' : 'NO'));

        if ($product->Home) {
            $normalized = $service->normalizeFtpPath($product->Home);
            $this->info("Normalized Path: " . $normalized);
            
            $images = $service->getProductImages($sku, $normalized);
            $this->info("Found Images: " . count($images));
            print_r($images);
            
            $urls = $service->getWooCommerceImageUrls($product->Home);
            $this->info("Generated URLs:");
            print_r($urls);
        } else {
            $this->warn("No Home path set for product");
        }
    }
}
