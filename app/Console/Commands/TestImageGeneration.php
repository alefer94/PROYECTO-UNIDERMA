<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImageService;
use Illuminate\Console\Command;

class TestImageGeneration extends Command
{
    protected $signature = 'test:image-urls {sku}';
    protected $description = 'Test image URL generation for a specific product SKU';

    public function handle(ProductImageService $imageService)
    {
        $sku = $this->argument('sku');
        $this->info("Testing image generation for SKU: {$sku}");

        $product = Product::where('CodCatalogo', $sku)->first();

        if (!$product) {
            $this->error("Product not found!");
            return;
        }

        $this->info("Product Home Path: " . ($product->Home ?? 'NULL'));
        $this->info("Product Link: " . ($product->Link ?? 'NULL'));

        $this->line("--------------------------------");
        $this->info("Generating URLs (Verify: FALSE)...");

        // Force verify=false to test the optimized logic
        $urls = $imageService->getWooCommerceImageUrls($product->Home, false);

        if (empty($urls)) {
            $this->warn("No images found in local path.");
            if ($product->Link) {
                $this->info("Fallback to Link: " . $product->Link);
            }
        } else {
            foreach ($urls as $index => $img) {
                $this->info("Image " . ($index + 1) . ": " . $img['src']);
            }
        }
    }
}
