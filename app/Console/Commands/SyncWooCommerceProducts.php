<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Services\WooCommerceService;
use Illuminate\Console\Command;

class SyncWooCommerceProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:sync-products 
                            {--limit= : Limit the number of products to sync}
                            {--sku= : Sync only a specific product by SKU}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync catalog products to WooCommerce';

    protected WooCommerceService $wooCommerceService;

    /**
     * Create a new command instance.
     */
    public function __construct(WooCommerceService $wooCommerceService)
    {
        parent::__construct();
        $this->wooCommerceService = $wooCommerceService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting WooCommerce product synchronization...');
        $this->newLine();

        try {
            // Get all products to sync (not just active ones)
            $query = Catalog::query();
            
            // Filter by SKU if provided
            if ($sku = $this->option('sku')) {
                $query->where('codCatalogo', $sku);
            }
            
            // Apply limit if provided
            if ($limit = $this->option('limit')) {
                $query->limit((int) $limit);
            }
            
            $catalogs = $query->get();
            
            if ($catalogs->isEmpty()) {
                $this->warn('⚠️  No products found to sync.');
                return Command::SUCCESS;
            }

            $this->info("📦 Found {$catalogs->count()} product(s) to sync");
            $this->newLine();

            // Initialize counters
            $results = [
                'total' => $catalogs->count(),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];

            // Create progress bar
            $bar = $this->output->createProgressBar($catalogs->count());
            $bar->start();

            // OPTIMIZATION: Fetch ALL WooCommerce products once instead of 1 by 1
            $this->info("\n📥 Fetching all products from WooCommerce...");
            
            try {
                // Get all products from WooCommerce (paginated if needed)
                $allWooProducts = $this->fetchAllWooCommerceProducts();
                $this->info("✅ Fetched {$allWooProducts->count()} products from WooCommerce");
                
                // Index by SKU for fast lookup
                $wooProductsBySku = $allWooProducts->keyBy('sku');
                
            } catch (\Exception $e) {
                $this->error("Failed to fetch WooCommerce products: {$e->getMessage()}");
                return Command::FAILURE;
            }

            $this->newLine();
            $bar->start();

            // Sync each product (now comparing in memory, not via API)
            foreach ($catalogs as $catalog) {
                try {
                    $productData = $this->mapCatalogToWooCommerce($catalog);
                    
                    // Check if product exists in WooCommerce (IN MEMORY - FAST)
                    $existingProduct = $wooProductsBySku->get($catalog->codCatalogo);

                    
                    if ($existingProduct) {
                        // Product exists in WooCommerce - compare data
                        $productId = $existingProduct->id;
                        
                        // Check if data has changed
                        if ($this->hasProductChanged($existingProduct, $productData, $catalog)) {
                            // Data differs - update WooCommerce with Laravel data
                            $this->wooCommerceService->updateProduct($productId, $productData);
                            $results['updated']++;
                        } else {
                            // Data is the same - skip update
                            $results['skipped']++;
                        }
                    } else {
                        // Product doesn't exist in WooCommerce - create it
                        $this->wooCommerceService->createProduct($productData);
                        $results['created']++;
                    }
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $this->error("\n❌ Failed to sync SKU: {$catalog->codCatalogo} - {$e->getMessage()}");
                }
                
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Display results
            $this->displayResults($results);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Synchronization failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Display sync results
     */
    protected function displayResults(array $results)
    {
        $this->info('✅ Synchronization completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Products', $results['total']],
                ['Created', "<fg=green>{$results['created']}</>"],
                ['Updated', "<fg=blue>{$results['updated']}</>"],
                ['Skipped (No Changes)', "<fg=yellow>{$results['skipped']}</>"],
                ['Failed', "<fg=red>{$results['failed']}</>"],
            ]
        );
    }

    /**
     * Map Catalog model to WooCommerce product format
     */
    protected function mapCatalogToWooCommerce(Catalog $catalog): array
    {
        return [
            // Basic information
            'name' => $catalog->nombre,
            'sku' => $catalog->codCatalogo,
            'description' => $catalog->descripcion,
            'short_description' => $catalog->corta,
            
            // Pricing
            'regular_price' => (string) $catalog->precio,
            
            // Stock management
            'stock_quantity' => $catalog->stock,
            'stock_status' => $catalog->stock > 0 ? 'instock' : 'outofstock',
            'manage_stock' => true,
            
            // Status
            'status' => $catalog->flgActivo == 1 ? 'publish' : 'draft',
            
            // Tags
            'tags' => $this->parseTags($catalog->pasCodTag),
            
            // Images - COMMENTED FOR TESTING
            // 'images' => $this->parseImages($catalog->home),
            
            // Product type and settings
            'type' => 'simple',
            'catalog_visibility' => 'visible',
            'virtual' => false,
            'downloadable' => false,
            'tax_status' => 'taxable',
            'reviews_allowed' => true,
            'sold_individually' => false,
            'backorders' => 'no',
            
            // Custom meta data
            'meta_data' => $this->buildMetaData($catalog),
        ];
    }

    /**
     * Parse tags from semicolon-separated string
     */
    protected function parseTags(?string $tags): array
    {
        if (empty($tags)) {
            return [];
        }
        
        return array_map(function($tag) {
            return ['name' => trim($tag)];
        }, explode(';', $tags));
    }

    /**
     * Parse images from URL
     */
    protected function parseImages(?string $imageUrl): array
    {
        if (empty($imageUrl)) {
            return [];
        }
        
        return [['src' => $imageUrl]];
    }

    /**
     * Build meta data array
     */
    protected function buildMetaData(Catalog $catalog): array
    {
        $metaData = [];
        
        $fields = [
            'codigo_tipo_catalogo' => $catalog->codTipcat,
            'codigo_clasificador' => $catalog->codClasificador,
            'codigo_subclasificador' => $catalog->codSubclasificador,
            'codigo_laboratorio' => $catalog->codLaboratorio,
            'registro_sanitario' => $catalog->registro,
            'presentacion' => $catalog->presentacion,
            'composicion' => $catalog->composicion,
            'beneficios' => $catalog->bemeficios,
            'modo_uso' => $catalog->modoUso,
            'contraindicaciones' => $catalog->contraindicaciones,
            'advertencias' => $catalog->advertencias,
            'precauciones' => $catalog->precauciones,
            'tipo_receta' => (string) $catalog->tipReceta,
            'mostrar_modo_uso' => (string) $catalog->showModo,
            'links_asociados' => $catalog->link,
        ];
        
        foreach ($fields as $key => $value) {
            if (!empty($value)) {
                $metaData[] = [
                    'key' => $key,
                    'value' => $value
                ];
            }
        }
        
        return $metaData;
    }

    /**
     * Check if product data has changed between WooCommerce and Laravel
     * Laravel is the source of truth - we only update WooCommerce if it differs
     */
     protected function hasProductChanged($existingProduct, array $newProductData, Catalog $catalog): bool
    {
        // Compare critical fields that should trigger an update
        
        // 1. Name - normalize special characters
        if ($this->normalizeText($existingProduct->name) !== $this->normalizeText($newProductData['name'] ?? '')) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: name");
            return true;
        }
        
        // 2. Description - normalize special characters
        if ($this->normalizeText($existingProduct->description) !== $this->normalizeText($newProductData['description'] ?? '')) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: description");
            return true;
        }
        
        // 3. Short description - normalize special characters
        $existingShort = $this->normalizeText($existingProduct->short_description);
        $newShort = $this->normalizeText($newProductData['short_description'] ?? '');
        if ($existingShort !== $newShort) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: short_description");
            $this->line("    WC: '$existingShort'");
            $this->line("    Laravel: '$newShort'");
            return true;
        }
        
        // 4. Price
        if ((string) ($existingProduct->regular_price ?? '0') !== (string) ($newProductData['regular_price'] ?? '0')) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: price (WC: {$existingProduct->regular_price} vs Laravel: {$newProductData['regular_price']})");
            return true;
        }
        
        // 5. Stock quantity
        if ((int) ($existingProduct->stock_quantity ?? 0) !== (int) ($newProductData['stock_quantity'] ?? 0)) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: stock_quantity");
            return true;
        }
        
        // 6. Stock status
        if (($existingProduct->stock_status ?? 'outofstock') !== ($newProductData['stock_status'] ?? 'outofstock')) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: stock_status");
            return true;
        }
        
        // 7. Status (publish/draft)
        if (($existingProduct->status ?? 'draft') !== ($newProductData['status'] ?? 'draft')) {
            $this->warn("  Change detected in SKU {$catalog->codCatalogo}: status");
            return true;
        }
        
        // 8. Images - SKIP if not syncing images (commented out in mapCatalogToWooCommerce)
        // Only compare if we're actually sending images
        if (isset($newProductData['images']) && !empty($newProductData['images'])) {
            $existingImageUrl = !empty($existingProduct->images) ? $existingProduct->images[0]->src : null;
            $newImageUrl = !empty($newProductData['images']) ? $newProductData['images'][0]['src'] : null;
            if ($existingImageUrl !== $newImageUrl) {
                $this->warn("  Change detected in SKU {$catalog->codCatalogo}: images");
                return true;
            }
        }
        
        // No changes detected
        return false;
    }

    /**
     * Normalize text for comparison: decode entities, strip tags, normalize special chars
     */
    protected function normalizeText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Strip HTML tags
        $text = strip_tags($text);
        
        // Normalize special characters to ASCII equivalents
        $text = str_replace(
            ["\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\xA6"],
            ["-", "-", "'", "'", '"', '"', "..."],
            $text
        );
        
        // Trim whitespace
        return trim($text);
    }

    /**
     * Fetch all products from WooCommerce with pagination
     */
    protected function fetchAllWooCommerceProducts()
    {
        $allProducts = collect();
        $page = 1;
        $perPage = 100; // WooCommerce max per page
        
        do {
            $products = $this->wooCommerceService->getProducts([
                'per_page' => $perPage,
                'page' => $page
            ]);
            
            if (!empty($products)) {
                $allProducts = $allProducts->merge($products);
                $page++;
            }
            
        } while (!empty($products) && count($products) === $perPage);
        
        return $allProducts;
    }
}

