<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\WooCommerceService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use PDOException;

class SyncWooCommerceProducts extends Command
{
    protected $signature = 'woocommerce:sync-products';
    protected $description = 'Synchronize products with WooCommerce using batch operations';
    
    protected $wooCommerceService;
    
    public function __construct(WooCommerceService $wooCommerceService)
    {
        parent::__construct();
        $this->wooCommerceService = $wooCommerceService;
    }
    
    public function handle()
    {
        $startTime = microtime(true);
        
        $this->info('🚀 Starting product synchronization...');
        $this->newLine();
        
        // Fetch all WooCommerce products
        $page = 1;
        $wcProducts = [];
        
        $this->info("Fetching products from WooCommerce...");
        
        try {
            do {
                $products = $this->wooCommerceService->getProducts([
                    'per_page' => 100,
                    'page' => $page,
                ]);
                
                $wcProducts = array_merge($wcProducts, $products);
                $page++;
            } while (count($products) === 100);
            
            $this->info("Total products fetched: " . count($wcProducts));
        } catch (\Exception $e) {
            $this->error("✗ Failed to fetch WooCommerce products");
            $this->error("Reason: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $this->newLine();
        
        // Fetch Laravel products with relationships
        $this->info("Fetching products from Laravel...");
        
        try {
            $laravelProducts = Product::with([
                'laboratory', 
                'tags',
                'catalogType',
                'catalogCategory',
                'catalogSubcategory'
            ])->get();
            $this->info("Total Laravel products: " . count($laravelProducts));
        } catch (QueryException $e) {
            $this->error("✗ Failed to fetch products from database");
            $this->error("Database Error: " . $e->getMessage());
            $this->newLine();
            $this->warn("Please check:");
            $this->warn("  - Database server is running");
            $this->warn("  - Database credentials in .env are correct");
            $this->warn("  - Database connection settings are valid");
            return Command::FAILURE;
        } catch (PDOException $e) {
            $this->error("✗ Database connection failed");
            $this->error("PDO Error: " . $e->getMessage());
            $this->newLine();
            $this->warn("Please check:");
            $this->warn("  - MySQL/MariaDB service is running");
            $this->warn("  - Database host and port are accessible");
            $this->warn("  - Firewall settings allow database connections");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("✗ Unexpected error while accessing database");
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $this->newLine();
        
        try {
            // Index WooCommerce products by SKU for fast lookup
            $wcProductsBySku = collect($wcProducts)->keyBy('sku');
            
            // Compare and categorize
            $this->info("=== COMPARING PRODUCTS ===");
            
            $productsToCreate = [];
            $productsToUpdate = [];
            $wcSkusInLaravel = [];
            
            foreach ($laravelProducts as $product) {
                $sku = $product->CodCatalogo;
                $wcSkusInLaravel[] = $sku;
                
                $wcProduct = $wcProductsBySku->get($sku);
                
                if ($wcProduct) {
                    // Product exists - check if needs update
                    if ($this->hasProductChanged($wcProduct, $product)) {
                        $productsToUpdate[] = $product;
                    }
                } else {
                    // Product doesn't exist - create it
                    $productsToCreate[] = $product;
                }
            }
            
            // Find orphaned products in WooCommerce
            $productsToDelete = [];
            foreach ($wcProducts as $wcProduct) {
                if (!in_array($wcProduct->sku, $wcSkusInLaravel)) {
                    $productsToDelete[] = $wcProduct->id;
                }
            }
            
            $this->info("Products to create: " . count($productsToCreate));
            $this->info("Products to update: " . count($productsToUpdate));
            $this->info("Products to delete: " . count($productsToDelete));
            
            $this->newLine();
            
            // Execute batch operations
            $this->info("=== BATCH OPERATIONS ===");
            $batchData = [];
            
            
            // Prepare delete operations
            if (!empty($productsToDelete)) {
                $batchData['delete'] = $productsToDelete;
            }
            
            // Prepare update operations
            if (!empty($productsToUpdate)) {
                $updateData = [];
                foreach ($productsToUpdate as $product) {
                    $wcProduct = $wcProductsBySku->get($product->CodCatalogo);
                    if ($wcProduct) {
                        $productData = $this->mapProductToWooCommerce($product);
                        $productData['id'] = $wcProduct->id; // Add WC product ID for update
                        $updateData[] = $productData;
                    }
                }
                
                if (!empty($updateData)) {
                    $batchData['update'] = $updateData;
                }
            }
            
            // Prepare create operations
            if (!empty($productsToCreate)) {
                $createData = [];
                foreach ($productsToCreate as $product) {
                    $createData[] = $this->mapProductToWooCommerce($product);
                }
                
                if (!empty($createData)) {
                    $batchData['create'] = $createData;
                }
            }
            
            // Execute batch if there's anything to do
            if (!empty($batchData)) {
                $this->info("Executing batch operations...");
                $this->info("  - Delete: " . count($batchData['delete'] ?? []));
                $this->info("  - Update: " . count($batchData['update'] ?? []));
                $this->info("  - Create: " . count($batchData['create'] ?? []));
                
                $this->newLine();
                
                // WooCommerce accepts max 100 items per batch, but we use 50 to avoid timeouts
                // Process deletes and updates together (usually smaller)
                if (!empty($batchData['delete']) || !empty($batchData['update'])) {
                    $deleteUpdateBatch = [];
                    
                    if (!empty($batchData['delete'])) {
                        $deleteUpdateBatch['delete'] = $batchData['delete'];
                    }
                    
                    if (!empty($batchData['update'])) {
                        // Chunk updates if more than 50
                        $updateChunks = array_chunk($batchData['update'], 50);
                        
                        foreach ($updateChunks as $index => $chunk) {
                            $batch = ['update' => $chunk];
                            
                            // Add deletes only to first batch
                            if ($index === 0 && !empty($batchData['delete'])) {
                                $batch['delete'] = $batchData['delete'];
                                $batch['force'] = true;
                            }
                            
                            try {
                                $response = $this->wooCommerceService->batchProducts($batch);
                                
                                if (isset($response->delete)) {
                                    $this->info("  ✓ Deleted: " . count($response->delete) . " products");
                                }
                                
                                if (isset($response->update)) {
                                    $this->info("  ✓ Updated: " . count($response->update) . " products (batch " . ($index + 1) . ")");
                                }
                            } catch (\Exception $e) {
                                $this->error("  ✗ Update batch " . ($index + 1) . " failed: " . $e->getMessage());
                            }
                        }
                    } elseif (!empty($batchData['delete'])) {
                        // Only deletes
                        try {
                            $response = $this->wooCommerceService->batchProducts([
                                'delete' => $batchData['delete'],
                                'force' => true
                            ]);
                            
                            if (isset($response->delete)) {
                                $this->info("  ✓ Deleted: " . count($response->delete) . " products");
                            }
                        } catch (\Exception $e) {
                            $this->error("  ✗ Delete batch failed: " . $e->getMessage());
                        }
                    }
                }
                
                // Process creates in chunks of 50
                if (!empty($batchData['create'])) {
                    $createChunks = array_chunk($batchData['create'], 50);
                    
                    foreach ($createChunks as $index => $chunk) {
                        try {
                            $response = $this->wooCommerceService->batchProducts(['create' => $chunk]);
                            
                            if (isset($response->create)) {
                                $this->info("  ✓ Created: " . count($response->create) . " products (batch " . ($index + 1) . "/" . count($createChunks) . ")");
                            }
                        } catch (\Exception $e) {
                            $this->error("  ✗ Create batch " . ($index + 1) . " failed: " . $e->getMessage());
                        }
                    }
                }
            } else {
                $this->info("No operations needed - everything is in sync");
            }
        } catch (\Exception $e) {
            $this->error("✗ Error during product comparison");
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->info("✅ Synchronization completed in {$duration} seconds!");
        
        return Command::SUCCESS;
    }
    
    /**
     * Map Laravel Product to WooCommerce product format
     */
    protected function mapProductToWooCommerce(Product $product): array
    {
        $data = [
            'name' => trim($product->Nombre), // Trim whitespace
            'type' => 'simple',
            'sku' => $product->CodCatalogo,
            'regular_price' => (string) $product->Precio,
            'description' => $product->Descripcion ?? '',
            'short_description' => $product->Corta ?? '',
            'manage_stock' => true,
            'stock_quantity' => $product->Stock ?? 0,
            'status' => $product->FlgActivo ? 'publish' : 'draft',
        ];
        
        // Add categories if available
        $categories = [];
        
        // Add laboratory as brand category
        if ($product->CodLaboratorio && $product->laboratory && $product->laboratory->WooCommerceCategoryId) {
            $categories[] = ['id' => $product->laboratory->WooCommerceCategoryId];
        }
        
        // Add tag categories
        if ($product->tags) {
            foreach ($product->tags as $tag) {
                if ($tag->WooCommerceCategoryId) {
                    $categories[] = ['id' => $tag->WooCommerceCategoryId];
                }
            }
        }
        
        // Add catalog category using cascade priority (Subcategory > Category > Type)
        $catalogCategoryId = $this->getCatalogCategoryId($product);
        if ($catalogCategoryId) {
            $categories[] = ['id' => $catalogCategoryId];
        }
        
        if (!empty($categories)) {
            $data['categories'] = $categories;
        }
        
        // Add images if available
        // if ($product->Link) {
        //     $data['images'] = [
        //         ['src' => $product->Link]
        //     ];
        // }
        
        // Add metadata
        $metaData = [];
        
        if ($product->Advertencias) {
            $metaData[] = [
                'key' => 'advertencias',
                'value' => $product->Advertencias
            ];
        }
        
        if ($product->Bemeficios) {
            $metaData[] = [
                'key' => 'beneficios',
                'value' => $product->Bemeficios
            ];
        }
        
        if ($product->Composicion) {
            $metaData[] = [
                'key' => 'composicion',
                'value' => $product->Composicion
            ];
        }
        
        if ($product->Contraindicaciones) {
            $metaData[] = [
                'key' => 'contraindicaciones',
                'value' => $product->Contraindicaciones
            ];
        }
        
        if ($product->ModoUso) {
            $metaData[] = [
                'key' => 'modo_de_uso',
                'value' => $product->ModoUso
            ];
        }
        
        if ($product->Precauciones) {
            $metaData[] = [
                'key' => 'precauciones',
                'value' => $product->Precauciones
            ];
        }
        
        if ($product->Presentacion) {
            $metaData[] = [
                'key' => 'presentacion',
                'value' => $product->Presentacion
            ];
        }
        
        if ($product->Registro) {
            $metaData[] = [
                'key' => 'registro',
                'value' => $product->Registro
            ];
        }
        
        if (!empty($metaData)) {
            $data['meta_data'] = $metaData;
        }
        
        return $data;
    }
    
    /**
     * Check if product data has changed between WooCommerce and Laravel
     */
    protected function hasProductChanged($wcProduct, Product $laravelProduct): bool
    {
        $changes = [];
        
        // Compare basic fields (normalize for comparison)
        $wcName = html_entity_decode(trim($wcProduct->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $laravelName = trim($laravelProduct->Nombre ?? '');
        if ($wcName !== $laravelName) {
            $changes[] = "name: WC='{$wcName}' vs Laravel='{$laravelName}'";
        }
        
        if ($wcProduct->regular_price !== (string) $laravelProduct->Precio) {
            $changes[] = "price: WC='{$wcProduct->regular_price}' vs Laravel='{$laravelProduct->Precio}'";
        }
        
        if (($wcProduct->stock_quantity ?? 0) !== ($laravelProduct->Stock ?? 0)) {
            $wcStock = $wcProduct->stock_quantity ?? 0;
            $laravelStock = $laravelProduct->Stock ?? 0;
            $changes[] = "stock: WC='{$wcStock}' vs Laravel='{$laravelStock}'";
        }
        
        $wcStatus = $wcProduct->status ?? 'draft';
        $laravelStatus = $laravelProduct->FlgActivo ? 'publish' : 'draft';
        if ($wcStatus !== $laravelStatus) {
            $changes[] = "status: WC='{$wcStatus}' vs Laravel='{$laravelStatus}'";
        }
        
        // Compare descriptions (normalize HTML, whitespace, and line breaks)
        $wcDescription = $this->normalizeText($wcProduct->description ?? '');
        $laravelDescription = $this->normalizeText($laravelProduct->Descripcion ?? '');
        if ($wcDescription !== $laravelDescription) {
            $wcPreview = substr($wcDescription, 0, 50);
            $laravelPreview = substr($laravelDescription, 0, 50);
            $changes[] = "description: WC='{$wcPreview}...' vs Laravel='{$laravelPreview}...'";
        }
        
        // Normalize short description: decode HTML entities first, then normalize
        $wcShortDescRaw = html_entity_decode($wcProduct->short_description ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $wcShortDesc = $this->normalizeText($wcShortDescRaw);
        $laravelShortDesc = $this->normalizeText($laravelProduct->Corta ?? '');
        if ($wcShortDesc !== $laravelShortDesc) {
            $wcPreview = substr($wcShortDesc, 0, 50);
            $laravelPreview = substr($laravelShortDesc, 0, 50);
            $changes[] = "short_description: WC='{$wcPreview}...' vs Laravel='{$laravelPreview}...'";
        }
        
        // Compare categories
        $wcCategoryIds = collect($wcProduct->categories ?? [])->pluck('id')->sort()->values()->toArray();
        $laravelCategoryIds = [];
        
        if ($laravelProduct->laboratory && $laravelProduct->laboratory->WooCommerceCategoryId) {
            $laravelCategoryIds[] = $laravelProduct->laboratory->WooCommerceCategoryId;
        }
        
        if ($laravelProduct->tags) {
            foreach ($laravelProduct->tags as $tag) {
                if ($tag->WooCommerceCategoryId) {
                    $laravelCategoryIds[] = $tag->WooCommerceCategoryId;
                }
            }
        }
        
        // Add catalog category using cascade priority (Subcategory > Category > Type)
        $catalogCategoryId = $this->getCatalogCategoryId($laravelProduct);
        if ($catalogCategoryId) {
            $laravelCategoryIds[] = $catalogCategoryId;
        }
        
        sort($laravelCategoryIds);
        
        if ($wcCategoryIds !== $laravelCategoryIds) {
            $wcCats = implode(',', $wcCategoryIds);
            $laravelCats = implode(',', $laravelCategoryIds);
            $changes[] = "categories: WC=[{$wcCats}] vs Laravel=[{$laravelCats}]";
        }
        
        // Compare metadata
        $wcMetaData = collect($wcProduct->meta_data ?? [])->keyBy('key');
        
        $metaFields = [
            'advertencias' => $laravelProduct->Advertencias,
            'beneficios' => $laravelProduct->Bemeficios,
            'composicion' => $laravelProduct->Composicion,
            'contraindicaciones' => $laravelProduct->Contraindicaciones,
            'modo_de_uso' => $laravelProduct->ModoUso,
            'precauciones' => $laravelProduct->Precauciones,
            'presentacion' => $laravelProduct->Presentacion,
            'registro' => $laravelProduct->Registro,
        ];
        
        foreach ($metaFields as $key => $laravelValue) {
            $wcMeta = $wcMetaData->get($key);
            $wcValue = $wcMeta->value ?? null;
            
            if ($wcValue !== $laravelValue) {
                $wcPreview = is_string($wcValue) ? substr($wcValue, 0, 30) : json_encode($wcValue);
                $laravelPreview = is_string($laravelValue) ? substr($laravelValue, 0, 30) : json_encode($laravelValue);
                $changes[] = "meta[{$key}]: WC='{$wcPreview}...' vs Laravel='{$laravelPreview}...'";
            }
        }
        
        // Log changes if any detected
        if (!empty($changes)) {
            $this->warn("  📝 Product '{$laravelProduct->Nombre}' (SKU: {$laravelProduct->CodCatalogo}) has changes:");
            foreach ($changes as $change) {
                $this->warn("     - {$change}");
            }
        }
        
        return !empty($changes);
    }
    
    /**
     * Normalize text for comparison: decode HTML entities, strip HTML, normalize whitespace and line breaks
     */
    protected function normalizeText(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        
        // Decode HTML entities FIRST (before stripping tags)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Strip HTML tags
        $text = strip_tags($text);
        
        // Reverse WordPress wptexturize() conversions
        // These are Unicode characters that WordPress converts to
        $text = str_replace([
            "\u{2014}", // em dash
            "\u{2013}", // en dash
            "\u{2018}", // left single quote
            "\u{2019}", // right single quote / apostrophe - THIS IS THE KEY ONE
            "\u{201C}", // left double quote
            "\u{201D}", // right double quote
            "\u{2026}", // ellipsis
            "\u{00D7}", // multiplication
            "\u{00F7}", // division
        ], [
            "--",
            "-",
            "'",
            "'", // Convert smart apostrophe to regular apostrophe
            '"',
            '"',
            "...",
            "x",
            "/",
        ], $text);
        
        // Normalize line breaks to \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Remove multiple consecutive line breaks
        $text = preg_replace("/\n{2,}/", "\n", $text);
        
        // Trim whitespace from each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);
        
        // Trim overall
        return trim($text);
    }
    
    /**
     * Get the catalog category ID using cascade priority logic
     * Priority: Subcategory > Category > Type
     * Returns only the most specific level available to avoid redundancy
     */
    protected function getCatalogCategoryId(Product $product): ?int
    {
        // Priority 1: Subcategory (most specific)
        if ($product->catalogSubcategory?->WooCommerceCategoryId) {
            return $product->catalogSubcategory->WooCommerceCategoryId;
        }
        
        // Priority 2: Category
        if ($product->catalogCategory?->WooCommerceCategoryId) {
            return $product->catalogCategory->WooCommerceCategoryId;
        }
        
        // Priority 3: Type (least specific)
        if ($product->catalogType?->WooCommerceCategoryId) {
            return $product->catalogType->WooCommerceCategoryId;
        }
        
        return null;
    }
}