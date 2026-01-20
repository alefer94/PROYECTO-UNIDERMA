<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooCommerceService;

class TestWCFetch extends Command
{
    protected $signature = 'debug:wc-fetch';
    protected $description = 'Test fetching all WC products';

    public function handle(WooCommerceService $wcService)
    {
        $this->info("Starting Fetch Test...");
        
        $page = 1;
        $all = 0;
        
        do {
            $this->info("Fetching page $page...");
            try {
                $products = $wcService->getProducts([
                    'per_page' => 100,
                    'page' => $page,
                ]);
                $count = count($products);
                $this->info("Page $page: Fetched $count products.");
                
                if ($count > 0) {
                    $this->info("First product ID: " . $products[0]->id);
                }
                
                $all += $count;
                $page++;
            } catch (\Exception $e) {
                $this->error("Exception on page $page: " . $e->getMessage());
                break;
            }
        } while (count($products) === 100);

        $this->info("Total fetched: $all");
    }
}
