<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\WooCommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductSyncController extends Controller
{
    protected WooCommerceService $wooCommerceService;

    public function __construct(WooCommerceService $wooCommerceService)
    {
        $this->wooCommerceService = $wooCommerceService;
    }

    /**
     * Sync all active products to WooCommerce
     */
    public function syncProducts()
    {
        try {
            // Get all products (not just active ones)
            $products = Product::all();
            
            $results = [
                'total' => $products->count(),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            // OPTIMIZATION: Fetch ALL WooCommerce products once
            try {
                $allWooProducts = $this->fetchAllWooCommerceProducts();
                $wooProductsBySku = collect($allWooProducts)->keyBy('sku');
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch WooCommerce products: ' . $e->getMessage()
                ], 500);
            }
            
            foreach ($products as $product) {
                try {
                    $productData = $this->mapProductToWooCommerce($product);
                    
                    // Check if product exists (IN MEMORY - FAST)
                    $existingProduct = $wooProductsBySku->get($product->CodCatalogo);
                    
                    if ($existingProduct) {
                        // Product exists - compare data
                        $productId = $existingProduct->id;
                        
                        // Only update if data has changed
                        if ($this->hasProductChanged($existingProduct, $productData, $catalog)) {
                            $this->wooCommerceService->updateProduct($productId, $productData);
                            $results['updated']++;
                        } else {
                            $results['skipped']++;
                        }
                    } else {
                        // Product doesn't exist - create it
                        $this->wooCommerceService->createProduct($productData);
                        $results['created']++;
                    }
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'product_id' => $product->id,
                        'sku' => $product->codCatalogo,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Bulk sync completed',
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            Log::error('Bulk sync failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Bulk sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products from WooCommerce
     */
    public function getWooCommerceProducts(Request $request)
    {
        try {
            $params = $request->only(['page', 'per_page', 'search', 'status']);
            $products = $this->wooCommerceService->getProducts($params);
            
            return response()->json([
                'success' => true,
                'products' => $products
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Map Catalog model to WooCommerce product format
     */
    protected function mapProductToWooCommerce(Product $product): array
    {
        return [
            // Basic information
            'name' => $product->Nombre,
            'sku' => $product->CodCatalogo,
            'description' => $product->Descripcion,
            'short_description' => $product->Corta,
            
            // Pricing
            'regular_price' => (string) $product->Precio,
            
            // Stock management
            'stock_quantity' => $product->Stock,
            'stock_status' => $product->Stock > 0 ? 'instock' : 'outofstock',
            'manage_stock' => true,
            
            // Status
            'status' => $product->FlgActivo == 1 ? 'publish' : 'draft',
            
            // Tags (convert semicolon-separated string to array)
            'tags' => $this->parseTags($product->PasCodTag),
            
            // Images - COMMENTED FOR TESTING
            // 'images' => $this->parseImages($product->Home),
            
            // Product type and settings
            'type' => 'simple',
            'catalog_visibility' => 'visible',
            'virtual' => false,
            'downloadable' => false,
            'tax_status' => 'taxable',
            'reviews_allowed' => true,
            'sold_individually' => false,
            'backorders' => 'no',
            
            // Custom meta data for pharmaceutical information
            'meta_data' => $this->buildMetaData($product),
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
        
        return [
            ['src' => $imageUrl]
        ];
    }

    /**
     * Build meta data array for pharmaceutical information
     */
    protected function buildMetaData(Product $product): array
    {
        $metaData = [];
        
        $fields = [
            'codigo_tipo_catalogo' => $product->CodTipcat,
            'codigo_clasificador' => $product->CodClasificador,
            'codigo_subclasificador' => $product->CodSubclasificador,
            'codigo_laboratorio' => $product->CodLaboratorio,
            'registro_sanitario' => $product->Registro,
            'presentacion' => $product->Presentacion,
            'composicion' => $product->Composicion,
            'beneficios' => $product->Bemeficios,
            'modo_uso' => $product->ModoUso,
            'contraindicaciones' => $product->Contraindicaciones,
            'advertencias' => $product->Advertencias,
            'precauciones' => $product->Precauciones,
            'tipo_receta' => (string) $product->TipReceta,
            'mostrar_modo_uso' => (string) $product->ShowModo,
            'links_asociados' => $product->Link,
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
     */
    protected function hasProductChanged($existingProduct, array $newProductData, Product $product): bool
    {
        // Normalize text: decode HTML entities, strip tags, normalize special chars
        $normalize = function($text) {
            if (empty($text)) return '';
            
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = strip_tags($text);
            
            
            // Normalize special characters to ASCII
            $replacements = [
                "\xE2\x80\x93" => '-', // en dash
                "\xE2\x80\x94" => '-', // em dash
                "\xE2\x80\x98" => "'", // left single quote
                "\xE2\x80\x99" => "'", // right single quote
                "\xE2\x80\x9C" => '"', // left double quote
                "\xE2\x80\x9D" => '"', // right double quote
                "\xE2\x80\xA6" => '...', // ellipsis
            ];
            $text = str_replace(array_keys($replacements), array_values($replacements), $text);
            
            return trim($text);
        };
        
        // Compare critical fields
        if ($normalize($existingProduct->name) !== $normalize($newProductData['name'])) return true;
        if ($normalize($existingProduct->description) !== $normalize($newProductData['description'])) return true;
        if ($normalize($existingProduct->short_description) !== $normalize($newProductData['short_description'])) return true;
        if ((string) ($existingProduct->regular_price ?? '0') !== (string) ($newProductData['regular_price'] ?? '0')) return true;
        if ((int) ($existingProduct->stock_quantity ?? 0) !== (int) ($newProductData['stock_quantity'] ?? 0)) return true;
        if (($existingProduct->stock_status ?? 'outofstock') !== ($newProductData['stock_status'] ?? 'outofstock')) return true;
        if (($existingProduct->status ?? 'draft') !== ($newProductData['status'] ?? 'draft')) return true;
        
        // Check image URL - only if we're actually sending images
        if (isset($newProductData['images']) && !empty($newProductData['images'])) {
            $existingImageUrl = !empty($existingProduct->images) ? $existingProduct->images[0]->src : null;
            $newImageUrl = !empty($newProductData['images']) ? $newProductData['images'][0]['src'] : null;
            if ($existingImageUrl !== $newImageUrl) return true;
        }
        
        return false;
    }

    /**
     * Fetch all products from WooCommerce with pagination
     */
    protected function fetchAllWooCommerceProducts()
    {
        $allProducts = collect();
        $page = 1;
        $perPage = 100;
        
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

