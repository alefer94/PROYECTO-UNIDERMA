<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncFromApi extends Command
{
    protected $signature = 'api:sync 
                            {entity : Entity to sync (laboratories, tags, products, etc.)}
                            {--params=* : Additional parameters in key=value format}';

    protected $description = 'Sync data from REST API to database';

    protected $serviceMap = [
        'laboratories' => \App\Services\Sync\LaboratorySyncService::class,
        'catalog-types' => \App\Services\Sync\CatalogTypeSyncService::class,
        'catalog-categories' => \App\Services\Sync\CatalogCategorySyncService::class,
        'catalog-subcategories' => \App\Services\Sync\CatalogSubcategorySyncService::class,
        'tag-categories' => \App\Services\Sync\TagCategorySyncService::class,
        'tag-subcategories' => \App\Services\Sync\TagSubcategorySyncService::class,
        'tags' => \App\Services\Sync\TagSyncService::class,
        'products' => \App\Services\Sync\ProductSyncService::class,
        'zones' => \App\Services\Sync\ZoneSyncService::class,
        // Add more as you create them
    ];

    public function handle()
    {
        $entity = $this->argument('entity');

        // Check if service exists
        if (! isset($this->serviceMap[$entity])) {
            $this->error("❌ Unknown entity: {$entity}");
            $this->info('Available entities: '.implode(', ', array_keys($this->serviceMap)));

            return Command::FAILURE;
        }

        $serviceClass = $this->serviceMap[$entity];
        $service = app($serviceClass);

        // Parse additional parameters
        $params = $this->parseParams();

        $this->info("🔄 Syncing {$entity}...");
        $this->newLine();

        if (! empty($params)) {
            $this->info('📋 Custom parameters:');
            foreach ($params as $key => $value) {
                $this->line("   • {$key}: {$value}");
            }
            $this->newLine();
        }

        // Execute sync
        $result = $service->sync($params);

        // Display results
        if ($result['success']) {
            $this->info("✅ {$result['message']}");
            $this->newLine();

            if (isset($result['stats'])) {
                $this->displayStats($result['stats']);
            }

            return Command::SUCCESS;
        }

        $this->error("❌ {$result['message']}");

        return Command::FAILURE;
    }

    /**
     * Parse --params options into array
     */
    protected function parseParams(): array
    {
        $params = [];

        foreach ($this->option('params') as $param) {
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Display sync statistics
     */
    protected function displayStats(array $stats): void
    {
        $this->info('📊 Estadísticas:');

        $rows = [];
        foreach ($stats as $key => $value) {
            $rows[] = [ucfirst($key), $value];
        }

        $this->table(['Métrica', 'Valor'], $rows);
        $this->newLine();
    }
}
