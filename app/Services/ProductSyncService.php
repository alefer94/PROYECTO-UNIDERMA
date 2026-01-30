<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    protected WooCommerceService $wooCommerceService;

    protected ProductImageService $productImageService;

    protected array $logs = [];

    protected ?int $releaseCategoryId = null;

    public function __construct(
        WooCommerceService $wooCommerceService,
        ProductImageService $productImageService
    ) {
        $this->wooCommerceService = $wooCommerceService;
        $this->productImageService = $productImageService;
    }

    /**
     * Execute the full synchronization process
     * Returns an array of stats/results to be displayed by the command
     */
    public function sync(?callable $logger = null, ?callable $onProgress = null): array
    {
        $start = microtime(true);
        $this->log($logger, '🚀 Starting product synchronization (Architectural Refactor)...');

        // 1. Fetch Data (Parallelizable in future, sequential for now)
        $this->log($logger, 'Fetching products from WooCommerce...');
        $wcProducts = $this->fetchAllWooCommerceProducts();
        $this->log($logger, 'Fetched '.count($wcProducts).' WC products.');

        $this->log($logger, 'Fetching products from Laravel...');
        $laravelProducts = $this->fetchLaravelProducts();
        $this->log($logger, 'Fetched '.$laravelProducts->count().' Laravel products.');

        // 1.5 Fetch Release Category ID
        $this->releaseCategoryId = $this->fetchReleaseCategoryId($logger);

        // 2. Index Data for O(1) lookup
        // Key by SKU for instant access
        $wcProductsBySku = collect($wcProducts)->keyBy('sku');

        // 3. Compare and Categorize
        $this->log($logger, '=== COMPARING PRODUCTS ===');
        $operations = $this->categorizeOperations($laravelProducts, $wcProductsBySku, $onProgress);

        $createCount = count($operations['create']);
        $updateCount = count($operations['update']);
        $deleteCount = count($operations['delete']);

        $this->log($logger, "To Create: {$createCount}");
        $this->log($logger, "To Update: {$updateCount}");
        $this->log($logger, "To Delete: {$deleteCount}");

        // 4. Batch Execution
        if ($createCount + $updateCount + $deleteCount > 0) {
            $this->log($logger, '=== EXECUTING BATCHES ===');
            $this->executeBatches($operations, $logger);
        } else {
            $this->log($logger, '✅ System is already in sync.');
        }

        $duration = round(microtime(true) - $start, 2);

        return [
            'duration' => $duration,
            'created' => $createCount,
            'updated' => $updateCount,
            'deleted' => $deleteCount,
        ];
    }

    protected function fetchAllWooCommerceProducts(): array
    {
        $page = 1;
        $allProducts = [];

        do {
            $products = $this->wooCommerceService->getProducts([
                'per_page' => 100,
                'page' => $page,
                'status' => 'any', // Include draft, pending, private, and trash
            ]);

            $allProducts = array_merge($allProducts, $products);
            $page++;
        } while (count($products) === 100);

        return $allProducts;
    }

    protected function fetchReleaseCategoryId(?callable $logger = null): ?int
    {
        $this->log($logger, 'Checking for "Lanzamientos" category (group-4-releases)...');

        try {
            $categories = $this->wooCommerceService->getCategories([
                'slug' => 'group-4-releases',
            ]);

            if (! empty($categories) && isset($categories[0]->id)) {
                $id = (int) $categories[0]->id;
                $this->log($logger, "✓ Found Lanzamientos category ID: {$id}");

                return $id;
            }
        } catch (\Throwable $e) {
            $this->log($logger, '⚠️ Could not fetch Lanzamientos category: '.$e->getMessage(), 'error');
        }

        $this->log($logger, 'ℹ️ Lanzamientos category (group-4-releases) not found or error occurred.');

        return null;
    }

    protected function fetchLaravelProducts(): Collection
    {
        return Product::with([
            'tags',
            'catalogSubcategory', // Corrected: Was .catalogCategory which doesn't exist
            'catalogCategory', // Direct category (when no subcategory)
            'catalogType',     // Direct type
            'laboratory',
        ])->get();
    }

    protected function categorizeOperations(Collection $laravelProducts, Collection $wcProductsBySku, ?callable $onProgress = null): array
    {
        $toCreate = [];
        $toUpdate = [];
        $seenSkus = [];

        if ($onProgress) {
            $onProgress('start', $laravelProducts->count());
        }

        foreach ($laravelProducts as $product) {
            $sku = (string) $product->CodCatalogo;
            $seenSkus[$sku] = true; // Mark as seen for O(1) delete check

            $wcProduct = $wcProductsBySku->get($sku);

            if ($wcProduct) {
                if ($this->hasProductChanged($wcProduct, $product)) {
                    // Start mapping immediately to save memory later?
                    // No, keep object ref for now, map in batch to save memory spikes
                    $toUpdate[] = [
                        'laravel' => $product,
                        'wc_id' => $wcProduct->id,
                    ];
                }
            } else {
                $toCreate[] = $product;
            }

            if ($onProgress) {
                $onProgress('advance');
            }
        }

        if ($onProgress) {
            $onProgress('finish');
        }

        // Optimized Orphans detection (O(N))
        // Instead of searching array, we just check our seen map
        $toDelete = [];
        foreach ($wcProductsBySku as $sku => $wcProduct) {
            if (! isset($seenSkus[$sku])) {
                $toDelete[] = $wcProduct->id;
            }
        }

        return [
            'create' => $toCreate,
            'update' => $toUpdate,
            'delete' => $toDelete,
        ];
    }

    protected function executeBatches(array $operations, ?callable $logger = null): void
    {
        // DELETE
        if (! empty($operations['delete'])) {
            $this->wooCommerceService->batchProducts([
                'delete' => $operations['delete'],
            ]);

            $this->log($logger, '✓ Deleted '.count($operations['delete']).' products.');
        }

        // UPDATE
        if (! empty($operations['update'])) {
            $payloads = [];

            foreach ($operations['update'] as $item) {
                $data = $this->mapProductToWooCommerce($item['laravel']);
                $data['id'] = $item['wc_id'];
                $payloads[] = $data;
            }

            $this->processBatchesSeparated($payloads, 'update', $logger);
        }

        // CREATE
        if (! empty($operations['create'])) {
            $payloads = [];

            foreach ($operations['create'] as $product) {
                $payloads[] = $this->mapProductToWooCommerce($product);
            }

            $this->processBatchesSeparated($payloads, 'create', $logger);
        }
    }

    protected function processBatchesSeparated(array $items, string $type, ?callable $logger = null): void
    {
        $withImages = [];
        $withoutImages = [];

        foreach ($items as $item) {
            if (! empty($item['images'])) {
                $withImages[] = $item;
            } else {
                $withoutImages[] = $item;
            }
        }

        if (! empty($withImages)) {
            $this->processChunked($withImages, $type, config('api-sync.batch_size_with_images', 3), $logger);
        }

        if (! empty($withoutImages)) {
            $this->processChunked($withoutImages, $type, config('api-sync.batch_size_no_images', 50), $logger);
        }
    }

    protected function processChunked(
        array $items,
        string $type,
        int $batchSize,
        ?callable $logger = null
    ): void {
        $chunks = array_chunk($items, $batchSize);
        $total = count($items);
        $processed = 0;

        foreach ($chunks as $index => $chunk) {
            try {
                $this->log(
                    $logger,
                    "  ⏳ {$type} batch ".($index + 1).' ('.count($chunk).' items)'
                );

                $result = $this->wooCommerceService->batchProducts([
                    $type => $chunk,
                ]);

                $successCount = 0;
                $errorCount = 0;

                if (isset($result->$type)) {
                    foreach ($result->$type as $item) {
                        if (isset($item->error)) {
                            $errorCount++;

                            Log::error('WooCommerce batch item error', [
                                'type' => $type,
                                'id' => $item->id ?? null,
                                'error' => $item->error,
                            ]);
                        } else {
                            $successCount++;
                        }
                    }
                }

                $processed += count($chunk);

                $this->log(
                    $logger,
                    "  ✓ {$type} batch done: {$successCount} OK, {$errorCount} errors ({$processed}/{$total})"
                );

            } catch (\Throwable $e) {
                Log::error('WooCommerce batch failed', [
                    'type' => $type,
                    'message' => $e->getMessage(),
                ]);

                $this->log($logger, '  ❌ Batch failed: '.$e->getMessage(), 'error');
            }

            // Anti-rate-limit / timeout
            if ($processed < $total) {
                usleep(750000); // 0.75s
            }
        }
    }

    protected function processBatch(array $items, string $type, ?callable $logger = null): void
    {
        // Dynamic batch sizing
        $hasImages = collect($items)->contains(fn ($i) => ! empty($i['images']));

        $batchSize = $hasImages
            ? config('api-sync.batch_size_with_images', 5)
            : config('api-sync.batch_size_no_images', 50);

        $chunks = array_chunk($items, $batchSize);
        $total = count($items);
        $processed = 0;

        foreach ($chunks as $index => $chunk) {
            try {
                $this->log($logger, "  ⏳ Sending {$type} batch ".($index + 1).'...');
                $result = $this->wooCommerceService->batchProducts([$type => $chunk]);

                $count = isset($result->$type) ? count($result->$type) : 0;
                $processed += count($chunk);

                $this->log($logger, "  ✓ {$type}d batch: {$count} items. ({$processed}/{$total})");

                // Verify if explicit errors were returned in the batch response
                // WC Batch API doesn't always throw 400 on partial failures, it might return errors in the body?
                // Actually WC V3 batch endpoints behave transactionally or return per-item errors?
                // Usually returns 200 with list of created/updated items.

            } catch (\Exception $e) {
                $this->log($logger, '  ❌ Error processing batch: '.$e->getMessage(), 'error');
                // Optional: Stop on first error or continue?
                // For now, logging error is sufficient to debug "seems not to work".
            }

            // Sleep to prevent rate limiting / timeouts
            if ($processed < $total) {
                sleep(1);
            }
        }
    }

    public function mapProductToWooCommerce(Product $product): array
    {
        $data = [
            'name' => trim($product->Nombre),
            'type' => 'simple',
            'sku' => $product->CodCatalogo,
            'regular_price' => (string) $product->Precio,
            'description' => $product->Descripcion ?? '',
            'short_description' => $product->Corta ?? '',
            'manage_stock' => true,
            'stock_quantity' => $product->Stock ?? 0,
            'status' => $product->FlgActivo ? 'publish' : 'draft',
        ];

        // Categories
        $categories = $this->getCategoryIds($product);
        if (! empty($categories)) {
            $data['categories'] = array_map(fn ($id) => ['id' => $id], $categories);
        }

        // Images
        $images = $this->productImageService->getWooCommerceImageUrls($product->Home, false);
        if (! empty($images)) {
            $data['images'] = $images;
        } elseif ($product->Link) {
            $data['images'] = [['src' => $product->Link]];
        }

        // Meta Data
        $meta = $this->buildMetaData($product);
        if (! empty($meta)) {
            $data['meta_data'] = $meta;
        }

        return $data;
    }

    protected function hasProductChanged($wcProduct, Product $laravelProduct): bool
    {
        static $imageCache = [];

        $sku = (string) $laravelProduct->CodCatalogo;

        if (! isset($imageCache[$sku])) {
            $imageCache[$sku] = $this->productImageService
                ->getWooCommerceImageUrls($laravelProduct->Home, false);
        }

        $tStart = microtime(true);
        $changes = [];

        // Price (Float comparison)
        if (abs((float) $wcProduct->regular_price - (float) $laravelProduct->Precio) > 0.001) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Price WC[{$wcProduct->regular_price}] != Laravel[{$laravelProduct->Precio}]");

            return true;
        }

        // Stock (Int comparison)
        if ((int) ($wcProduct->stock_quantity ?? 0) !== (int) ($laravelProduct->Stock ?? 0)) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Stock WC[{$wcProduct->stock_quantity}] != Laravel[{$laravelProduct->Stock}]");

            return true;
        }

        // Status
        $wcStatus = $wcProduct->status ?? 'draft';
        $localStatus = $laravelProduct->FlgActivo ? 'publish' : 'draft';
        if ($wcStatus !== $localStatus) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Status WC[{$wcStatus}] != Laravel[{$localStatus}]");

            return true;
        }

        // Name
        $wcName = html_entity_decode(trim($wcProduct->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($wcName !== trim($laravelProduct->Nombre ?? '')) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Name");

            return true;
        }

        // Description & Short Description (Normalized)
        if ($this->normalizeText($wcProduct->description ?? '') !== $this->normalizeText($laravelProduct->Descripcion ?? '')) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Description");

            return true;
        }
        if ($this->normalizeText($wcProduct->short_description ?? '') !== $this->normalizeText($laravelProduct->Corta ?? '')) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Short Description");

            return true;
        }

        // Categories
        $wcCategoryIds = collect($wcProduct->categories ?? [])->pluck('id')->sort()->values()->toArray();
        $localCategoryIds = $this->getCategoryIds($laravelProduct);
        sort($localCategoryIds);

        if ($wcCategoryIds !== $localCategoryIds) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Categories");

            return true;
        }

        // Meta Data
        $wcMetaData = collect($wcProduct->meta_data ?? [])->keyBy('key');
        $localMeta = $this->buildMetaData($laravelProduct);

        foreach ($localMeta as $meta) {
            $key = $meta['key'];
            $val = $meta['value'];

            $wcVal = $wcMetaData->get($key)?->value;
            if ($wcVal !== $val) {
                Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Meta [{$key}]");

                return true;
            }
        }

        // Images
        // Calculate what local images *should* be
        $localImages = $imageCache[$sku];

        if (empty($localImages) && $laravelProduct->Link) {
            $localImages = [['src' => $laravelProduct->Link]];
        }

        $localFilenames = collect($localImages)
            ->pluck('src')
            ->map(fn ($url) => $this->normalizeFilename(basename($url)))
            ->sort()
            ->values()
            ->toArray();

        $wcFilenames = collect($wcProduct->images ?? [])
            ->pluck('src')
            ->map(fn ($url) => $this->normalizeFilename(basename($url)))
            ->sort()
            ->values()
            ->toArray();

        // Compare counts first
        if (count($localFilenames) !== count($wcFilenames)) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Image Count Local[".count($localFilenames).'] != WC['.count($wcFilenames).']');

            return true;
        }

        if ($localFilenames !== $wcFilenames) {
            Log::info("SKU {$laravelProduct->CodCatalogo} Changed: Images Mismatch");

            Log::info('  Local: '.json_encode($localFilenames));
            Log::info('  WC: '.json_encode($wcFilenames));

            return true;
        }

        $duration = microtime(true) - $tStart;
        if ($duration > 0.1) {
            Log::warning("Slow Comparison for SKU {$laravelProduct->CodCatalogo}: ".round($duration, 4).'s');
        }

        return false;
    }

    protected function normalizeFilename(string $filename): string
    {
        // 1. Remove all extensions recursively (e.g. image.jpg.jpg -> image.jpg -> image)
        $name = $filename;
        while (($ext = pathinfo($name, PATHINFO_EXTENSION)) !== '') {
            $name = pathinfo($name, PATHINFO_FILENAME);
        }

        // 2. Remove WooCommerce suffixes like -1, -4, -15 recursively
        // e.g. image-1-1 -> image-1 -> image
        while (preg_match('/-\d+$/', $name)) {
            $name = preg_replace('/-\d+$/', '', $name);
        }

        return $name;
    }

    protected function normalizeText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{2,}/", "\n", $text);

        // Basic punctuation normalization often helpful
        $text = str_replace(
            ["\u{2014}", "\u{2013}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"],
            ['--', '-', "'", "'", '"', '"'],
            $text
        );

        return trim($text);
    }

    // -- Private Helpers --

    protected function log(?callable $logger, string $message, string $type = 'info'): void
    {
        if ($logger) {
            $logger($message, $type);
        } else {
            Log::info($message);
        }
    }

    protected function getCategoryIds(Product $product): array
    {
        $ids = [];
        if ($product->laboratory?->WooCommerceCategoryId) {
            $ids[] = (int) $product->laboratory->WooCommerceCategoryId;
        }
        foreach ($product->tags as $tag) {
            if ($tag->WooCommerceCategoryId) {
                $ids[] = (int) $tag->WooCommerceCategoryId;
            }
        }

        $catId = $this->getCatalogCategoryId($product);
        if ($catId) {
            $ids[] = (int) $catId;
        }

        // Add Release Category if applicable
        if ($product->FlgLanzamiento && $this->releaseCategoryId) {
            $ids[] = $this->releaseCategoryId;
        }

        return array_unique($ids);
    }

    protected function getCatalogCategoryId(Product $product): ?int
    {
        return $product->catalogSubcategory?->WooCommerceCategoryId
            ?? $product->catalogCategory?->WooCommerceCategoryId
            ?? $product->catalogType?->WooCommerceCategoryId;
    }

    protected function buildMetaData(Product $product): array
    {
        $fields = [
            'advertencias' => $product->Advertencias,
            'beneficios' => $product->Bemeficios,
            'composicion' => $product->Composicion,
            'contraindicaciones' => $product->Contraindicaciones,
            'modo_de_uso' => $product->ModoUso,
            'precauciones' => $product->Precauciones,
            'presentacion' => $product->Presentacion,
            'registro' => $product->Registro,
            'marca' => $product->laboratory?->NomLaboratorio,
        ];

        $meta = [];
        foreach ($fields as $key => $value) {
            if ($value) {
                $meta[] = ['key' => $key, 'value' => $value];
            }
        }

        return $meta;
    }
}
