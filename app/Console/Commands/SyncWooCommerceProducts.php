<?php

namespace App\Console\Commands;

use App\Services\ProductSyncService;
use Illuminate\Console\Command;

class SyncWooCommerceProducts extends Command
{
    protected $signature = 'woocommerce:sync-products';

    protected $description = 'Synchronize products with WooCommerce using optimized batch operations (Refactored)';

    protected $productSyncService;

    public function __construct(ProductSyncService $productSyncService)
    {
        parent::__construct();
        $this->productSyncService = $productSyncService;
    }

    public function handle()
    {
        // Inject a simple logger closure to output to console
        $logger = function ($message, $type = 'info') {
            $this->info($message);
        };

        $onProgress = function ($action, $value = null) {
            static $progressBar;
            
            switch ($action) {
                case 'start':
                    $progressBar = $this->output->createProgressBar($value);
                    $progressBar->start();
                    break;
                case 'advance':
                    if ($progressBar) {
                        $progressBar->advance();
                    }
                    break;
                case 'finish':
                    if ($progressBar) {
                        $progressBar->finish();
                        $this->newLine();
                    }
                    break;
            }
        };

        $stats = $this->productSyncService->sync($logger, $onProgress);

        $this->newLine();
        $this->info("✅ Synchronization completed in {$stats['duration']} seconds!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Deleted', $stats['deleted']],
                ['Duration', $stats['duration'].'s'],
            ]
        );

        return Command::SUCCESS;
    }
}
