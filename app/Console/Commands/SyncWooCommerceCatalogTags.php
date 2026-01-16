<?php

namespace App\Console\Commands;

use App\Models\TagCategory;
use App\Models\TagSubcategory;
use App\Models\Tag;
use App\Services\WooCommerceService;
use Illuminate\Console\Command;

class SyncWooCommerceCatalogTags extends Command
{
    protected $signature = 'woocommerce:sync-catalog-tags';
    protected $description = 'Sync tag hierarchy to WooCommerce';

    protected WooCommerceService $wooCommerceService;

    public function __construct(WooCommerceService $wooCommerceService)
    {
        parent::__construct();
        $this->wooCommerceService = $wooCommerceService;
    }

    public function handle()
    {
        $startTime = microtime(true);
        
        $this->info('🚀 Starting tag synchronization...');
        
        // Obtener todos los catálogos de WooCommerce con paginación
        $page = 1;
        $wcCategories = [];
        
        try {
            do {
                $categories = $this->wooCommerceService->getCategories([
                    'per_page' => 100,
                    'page' => $page,
                ]);
                
                $wcCategories = array_merge($wcCategories, $categories);
                $page++;
            } while (count($categories) === 100);
            
            $this->info("Total categories fetched: " . count($wcCategories));
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            $this->error("✗ Failed to connect to WooCommerce API");
            
            if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
                $this->error("Reason: Connection timeout - The WooCommerce server is taking too long to respond");
                $this->line("Suggestion: Check your internet connection or try again later");
            } elseif (str_contains($errorMessage, 'Could not resolve host')) {
                $this->error("Reason: Cannot reach WooCommerce server - The API endpoint is unreachable");
                $this->line("Suggestion: Verify the WooCommerce URL in your .env file");
            } elseif (str_contains($errorMessage, 'Unauthorized') || str_contains($errorMessage, '401')) {
                $this->error("Reason: Authentication failed - Invalid API credentials");
                $this->line("Suggestion: Check your WooCommerce API key and secret in .env");
            } elseif (str_contains($errorMessage, 'Connection refused')) {
                $this->error("Reason: WooCommerce API is offline or unreachable");
                $this->line("Suggestion: Verify that your WooCommerce site is online");
            } elseif (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                $this->error("Reason: Invalid response from WooCommerce - The API returned malformed data");
                $this->line("Suggestion: Check if your WooCommerce site is working properly or has a server error");
            } else {
                $this->error("Reason: " . $errorMessage);
            }
            
            return Command::FAILURE;
        }
        
        // Traer datos de los modelos de Laravel
        $laravelTagCategories = TagCategory::all();
        $laravelTagSubcategories = TagSubcategory::all();
        $laravelTags = Tag::all();
        
        $this->info("Laravel TagCategory: " . count($laravelTagCategories));
        $this->info("Laravel TagSubcategory: " . count($laravelTagSubcategories));
        $this->info("Laravel Tag: " . count($laravelTags));
        
        // Obtener descendientes por nivel usando slug patterns
        $tagCatalog = [];
        $tagSubCatalog = [];
        $tag = [];
        
        // Nivel 1: TagCategories (parent: 0, slug: cat-*)
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'cat-')) {
                $tagCatalog[] = $category;
            }
        }
        
        // Nivel 2: TagSubcategories (slug: subcat-*)
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'subcat-')) {
                $tagSubCatalog[] = $category;
            }
        }
        
        // Nivel 3: Tags (slug: tag-*)
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'tag-')) {
                $tag[] = $category;
            }
        }
        
        $this->info("TagCatalog: " . count($tagCatalog));
        $this->info("TagSubCatalog: " . count($tagSubCatalog));
        $this->info("Tag: " . count($tag));
        
        // Detectar y re-enlazar categorías huérfanas
        $this->newLine();
        $this->info("=== DETECTING ORPHANED CATEGORIES ===");
        
        $orphanedCategories = [];
        $orphanedSubcategories = [];
        $orphanedTags = [];
        
        // Detectar TagCategories huérfanas (parent != 0, deben ser raíz)
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'cat-') && $category->parent !== 0) {
                $orphanedCategories[] = $category;
                $this->warn("  Found orphaned TagCategory: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Detectar TagSubcategories huérfanas (parent no es un TagCatalog válido)
        $validCatalogIds = array_map(fn($cat) => $cat->id, $tagCatalog);
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'subcat-') && !in_array($category->parent, $validCatalogIds)) {
                $orphanedSubcategories[] = $category;
                $this->warn("  Found orphaned TagSubcategory: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Detectar Tags huérfanos (parent no es un TagSubcatalog válido)
        $validSubcatalogIds = array_map(fn($subcat) => $subcat->id, $tagSubCatalog);
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'tag-') && !in_array($category->parent, $validSubcatalogIds)) {
                $orphanedTags[] = $category;
                $this->warn("  Found orphaned Tag: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Re-enlazar categorías huérfanas
        $relinkData = [];
        
        // Re-enlazar TagCategories a raíz (parent: 0)
        foreach ($orphanedCategories as $orphan) {
            $relinkData[] = [
                'id' => $orphan->id,
                'parent' => 0  // TagCategories son categorías raíz
            ];
            $tagCatalog[] = $orphan; // Agregar a la lista
        }
        
        // Re-enlazar TagSubcategories a su TagCategory correcto
        foreach ($orphanedSubcategories as $orphan) {
            // Extraer IdClasificador del slug (subcat-{IdSubClasificador}-{nombre})
            preg_match('/subcat-(\d+)-/', $orphan->slug, $matches);
            if (isset($matches[1])) {
                $idSubClasificador = (int)$matches[1];
                $laravelSubcat = $laravelTagSubcategories->firstWhere('IdSubClasificador', $idSubClasificador);
                
                if ($laravelSubcat) {
                    $parentCat = $laravelTagCategories->firstWhere('IdClasificador', $laravelSubcat->IdClasificador);
                    
                    if ($parentCat && $parentCat->WooCommerceCategoryId) {
                        $relinkData[] = [
                            'id' => $orphan->id,
                            'parent' => $parentCat->WooCommerceCategoryId
                        ];
                        $tagSubCatalog[] = $orphan;
                    }
                }
            }
        }
        
        // Re-enlazar Tags a su TagSubcategory correcto
        foreach ($orphanedTags as $orphan) {
            // Extraer IdTag del slug (tag-{IdTag}-{nombre})
            preg_match('/tag-(\d+)-/', $orphan->slug, $matches);
            if (isset($matches[1])) {
                $idTag = (int)$matches[1];
                $laravelTag = $laravelTags->firstWhere('IdTag', $idTag);
                
                if ($laravelTag) {
                    $parentSubcat = $laravelTagSubcategories->firstWhere('IdSubClasificador', $laravelTag->IdSubClasificador);
                    
                    if ($parentSubcat && $parentSubcat->WooCommerceCategoryId) {
                        $relinkData[] = [
                            'id' => $orphan->id,
                            'parent' => $parentSubcat->WooCommerceCategoryId
                        ];
                        $tag[] = $orphan;
                    }
                }
            }
        }
        
        // Ejecutar re-enlace si hay datos
        if (!empty($relinkData)) {
            $this->warn("Re-linking " . count($relinkData) . " orphaned categories to correct parents");
            
            try {
                $response = $this->wooCommerceService->batchCategories(['update' => $relinkData]);
                $this->info("  ✓ Re-linked " . count($response->update) . " categories");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to re-link: " . $e->getMessage());
            }
        } else {
            $this->info("No orphaned categories found");
        }
        
        // Obtener todos los WooCommerceCategoryId de Laravel y enlazar faltantes
        $laravelWcIds = [];
        $categoriesToCreate = [];
        $subcategoriesToCreate = [];
        $tagsToCreate = [];
        $linkedCount = 0;
        
        $this->newLine();
        $this->info("=== LINKING LARAVEL RECORDS TO WOOCOMMERCE ===");
        
        // Procesar TagCategory
        foreach ($laravelTagCategories as $cat) {
            if ($cat->WooCommerceCategoryId) {
                $laravelWcIds[] = $cat->WooCommerceCategoryId;
            } else {
                // Buscar por slug en WooCommerce
                $expectedSlug = $this->generateSlug('cat', $cat->IdClasificador, $cat->Nombre);
                $found = $this->findBySlug($tagCatalog, $expectedSlug);
                
                if ($found) {
                    // Enlazar
                    $cat->WooCommerceCategoryId = $found->id;
                    $cat->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->line("  Linked TagCategory '{$cat->Nombre}' to WC ID: {$found->id}");
                } else {
                    // No existe, agregar a lista para crear
                    $categoriesToCreate[] = $cat;
                }
            }
        }
        
        // Procesar TagSubcategory
        foreach ($laravelTagSubcategories as $subcat) {
            if ($subcat->WooCommerceCategoryId) {
                $laravelWcIds[] = $subcat->WooCommerceCategoryId;
            } else {
                $expectedSlug = $this->generateSlug('subcat', $subcat->IdSubClasificador, $subcat->Nombre);
                $found = $this->findBySlug($tagSubCatalog, $expectedSlug);
                
                if ($found) {
                    $subcat->WooCommerceCategoryId = $found->id;
                    $subcat->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->line("  Linked TagSubcategory '{$subcat->Nombre}' to WC ID: {$found->id}");
                } else {
                    $subcategoriesToCreate[] = $subcat;
                }
            }
        }
        
        // Procesar Tag
        foreach ($laravelTags as $t) {
            if ($t->WooCommerceCategoryId) {
                $laravelWcIds[] = $t->WooCommerceCategoryId;
            } else {
                $expectedSlug = $this->generateSlug('tag', $t->IdTag, $t->Nombre);
                $found = $this->findBySlug($tag, $expectedSlug);
                
                if ($found) {
                    $t->WooCommerceCategoryId = $found->id;
                    $t->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->line("  Linked Tag '{$t->Nombre}' to WC ID: {$found->id}");
                } else {
                    $tagsToCreate[] = $t;
                }
            }
        }
        
        $this->info("Linked {$linkedCount} records");
        $this->info("Categories to create: " . count($categoriesToCreate));
        $this->info("Subcategories to create: " . count($subcategoriesToCreate));
        $this->info("Tags to create: " . count($tagsToCreate));
        
        $this->info("Total WooCommerce IDs in Laravel: " . count($laravelWcIds));
        
        // Identificar categorías huérfanas con lógica en cascada
        $orphanedCategories = [];
        $orphanedIds = [];
        
        // Nivel 1: TagCatalog huérfanos
        foreach ($tagCatalog as $wcCat) {
            if (!in_array($wcCat->id, $laravelWcIds)) {
                $orphanedCategories[] = $wcCat;
                $orphanedIds[] = $wcCat->id;
                
                // Marcar todos sus descendientes como huérfanos en cascada
                foreach ($tagSubCatalog as $wcSubCat) {
                    if ($wcSubCat->parent === $wcCat->id && !in_array($wcSubCat->id, $orphanedIds)) {
                        $orphanedCategories[] = $wcSubCat;
                        $orphanedIds[] = $wcSubCat->id;
                        
                        // Marcar todos los tags hijos de este subcatalog
                        foreach ($tag as $wcTag) {
                            if ($wcTag->parent === $wcSubCat->id && !in_array($wcTag->id, $orphanedIds)) {
                                $orphanedCategories[] = $wcTag;
                                $orphanedIds[] = $wcTag->id;
                            }
                        }
                    }
                }
            }
        }
        
        // Nivel 2: TagSubCatalog huérfanos (que no fueron marcados en cascada)
        foreach ($tagSubCatalog as $wcSubCat) {
            if (!in_array($wcSubCat->id, $laravelWcIds) && !in_array($wcSubCat->id, $orphanedIds)) {
                $orphanedCategories[] = $wcSubCat;
                $orphanedIds[] = $wcSubCat->id;
                
                // Marcar todos sus tags hijos como huérfanos
                foreach ($tag as $wcTag) {
                    if ($wcTag->parent === $wcSubCat->id && !in_array($wcTag->id, $orphanedIds)) {
                        $orphanedCategories[] = $wcTag;
                        $orphanedIds[] = $wcTag->id;
                    }
                }
            }
        }
        
        // Nivel 3: Tag huérfanos (que no fueron marcados en cascada)
        foreach ($tag as $wcTag) {
            if (!in_array($wcTag->id, $laravelWcIds) && !in_array($wcTag->id, $orphanedIds)) {
                $orphanedCategories[] = $wcTag;
                $orphanedIds[] = $wcTag->id;
            }
        }
        
        $this->info("Orphaned categories in WooCommerce (with cascade): " . count($orphanedCategories));
        
        // Preparar IDs para eliminar
        $idsToDelete = [];
        if (count($orphanedCategories) > 0) {
            $this->warn("Categories to remove from WooCommerce:");
            foreach ($orphanedCategories as $orphan) {
                $this->line("  - ID: {$orphan->id}, Name: {$orphan->name}, Slug: {$orphan->slug}, Parent: {$orphan->parent}");
                $idsToDelete[] = $orphan->id;
            }
        }
        
        // Combinar todos los descendientes (solo niveles de tags)
        $descendants = array_merge($tagCatalog, $tagSubCatalog, $tag);
        
        $this->info("Total categories in hierarchy: " . count($descendants));
        
        // Filtrar $wcCategories para mantener solo los descendientes
        $validIds = array_map(fn($cat) => $cat->id, $descendants);
        $wcCategories = array_filter($wcCategories, fn($cat) => in_array($cat->id, $validIds));
        
        $this->info("Filtered categories: " . count($wcCategories) . " remaining");
        
        // Comparar datos entre Laravel y WooCommerce
        $this->newLine();
        $this->info("=== COMPARING LARAVEL AND WOOCOMMERCE DATA ===");
        
        $discrepancies = [];
        
        // Comparar TagCategory
        foreach ($laravelTagCategories as $laravelCat) {
            if ($laravelCat->WooCommerceCategoryId) {
                $wcCat = null;
                foreach ($tagCatalog as $wc) {
                    if ($wc->id === $laravelCat->WooCommerceCategoryId) {
                        $wcCat = $wc;
                        break;
                    }
                }
                
                if ($wcCat && $wcCat->name !== $laravelCat->Nombre) {
                    $discrepancies[] = [
                        'level' => 'TagCategory',
                        'id' => $wcCat->id,
                        'laravel_name' => $laravelCat->Nombre,
                        'wc_name' => $wcCat->name,
                    ];
                }
            }
        }
        
        // Comparar TagSubcategory
        foreach ($laravelTagSubcategories as $laravelSubCat) {
            if ($laravelSubCat->WooCommerceCategoryId) {
                $wcSubCat = null;
                foreach ($tagSubCatalog as $wc) {
                    if ($wc->id === $laravelSubCat->WooCommerceCategoryId) {
                        $wcSubCat = $wc;
                        break;
                    }
                }
                
                if ($wcSubCat && $wcSubCat->name !== $laravelSubCat->Nombre) {
                    $discrepancies[] = [
                        'level' => 'TagSubcategory',
                        'id' => $wcSubCat->id,
                        'laravel_name' => $laravelSubCat->Nombre,
                        'wc_name' => $wcSubCat->name,
                    ];
                }
            }
        }
        
        // Comparar Tag
        foreach ($laravelTags as $laravelTag) {
            if ($laravelTag->WooCommerceCategoryId) {
                $wcTag = null;
                foreach ($tag as $wc) {
                    if ($wc->id === $laravelTag->WooCommerceCategoryId) {
                        $wcTag = $wc;
                        break;
                    }
                }
                
                if ($wcTag && $wcTag->name !== $laravelTag->Nombre) {
                    $discrepancies[] = [
                        'level' => 'Tag',
                        'id' => $wcTag->id,
                        'laravel_name' => $laravelTag->Nombre,
                        'wc_name' => $wcTag->name,
                    ];
                }
            }
        }
        
        $this->info("Found " . count($discrepancies) . " name discrepancies");
        
        // Separar discrepancias por nivel para acciones específicas
        $categoriesToUpdate = [];
        $subcategoriesToUpdate = [];
        $tagsToUpdate = [];
        
        foreach ($discrepancies as $disc) {
            if ($disc['level'] === 'TagCategory') {
                $categoriesToUpdate[] = $disc;
            } elseif ($disc['level'] === 'TagSubcategory') {
                $subcategoriesToUpdate[] = $disc;
            } elseif ($disc['level'] === 'Tag') {
                $tagsToUpdate[] = $disc;
            }
        }
        
        $this->info("Categories to update: " . count($categoriesToUpdate));
        $this->info("Subcategories to update: " . count($subcategoriesToUpdate));
        $this->info("Tags to update: " . count($tagsToUpdate));

        // Construir batch data para actualizar
        $updateData = [];
        
        foreach ($categoriesToUpdate as $cat) {
            $laravelCat = $laravelTagCategories->firstWhere('WooCommerceCategoryId', $cat['id']);
            
            $updateData[] = [
                'id' => $cat['id'],
                'name' => $cat['laravel_name'],
                'slug' => $this->generateSlug('cat', $laravelCat->IdClasificador, $cat['laravel_name'])
            ];
        }
        
        foreach ($subcategoriesToUpdate as $subcat) {
            $laravelSubcat = $laravelTagSubcategories->firstWhere('WooCommerceCategoryId', $subcat['id']);
            
            $updateData[] = [
                'id' => $subcat['id'],
                'name' => $subcat['laravel_name'],
                'slug' => $this->generateSlug('subcat', $laravelSubcat->IdSubClasificador, $subcat['laravel_name'])
            ];
        }
        
        foreach ($tagsToUpdate as $tag) {
            $laravelTag = $laravelTags->firstWhere('WooCommerceCategoryId', $tag['id']);
            
            $updateData[] = [
                'id' => $tag['id'],
                'name' => $tag['laravel_name'],
                'slug' => $this->generateSlug('tag', $laravelTag->IdTag, $tag['laravel_name'])
            ];
        }
        
        // ========== BATCH 1: DELETE + UPDATE ==========
        $this->newLine();
        $this->info("=== BATCH OPERATIONS ===");
        
        $batchData = [];
        
        if (!empty($idsToDelete)) {
            $batchData['delete'] = $idsToDelete;
            $batchData['force'] = true;
        }
        
        if (!empty($updateData)) {
            $batchData['update'] = $updateData;
        }
        
        if (!empty($batchData)) {
            $this->info("Batch 1: Delete + Update");
            $this->info("  - Delete: " . count($idsToDelete ?? []));
            $this->info("  - Update: " . count($updateData ?? []));
            
            try {
                $response = $this->wooCommerceService->batchCategories($batchData);
                
                if (isset($response->delete)) {
                    $this->info("  ✓ Deleted: " . count($response->delete) . " items");
                }
                
                if (isset($response->update)) {
                    $this->info("  ✓ Updated: " . count($response->update) . " items");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                
                $this->error("  ✗ Batch operation failed");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data (possible server error)");
                } elseif (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
                    $this->error("  Reason: Request timeout - WooCommerce server took too long");
                } elseif (str_contains($errorMessage, 'Unauthorized') || str_contains($errorMessage, '401')) {
                    $this->error("  Reason: Authentication failed");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        // ========== BATCH 2: CREATE TAGCATEGORIES ==========
        if (!empty($categoriesToCreate)) {
            $this->info("Batch 2: Create TagCategories (" . count($categoriesToCreate) . ")");
            
            $createCatData = [];
            foreach ($categoriesToCreate as $cat) {
                $createCatData[] = [
                    'name' => $cat->Nombre,
                    'slug' => $this->generateSlug('cat', $cat->IdClasificador, $cat->Nombre),
                    'parent' => 0  // TagCategories son categorías raíz
                ];
            }
            
            try {
                $response = $this->wooCommerceService->batchCategories(['create' => $createCatData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $categoriesToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $categoriesToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " TagCategories");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create TagCategories");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data");
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $this->error("  Reason: Request timeout");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        // ========== BATCH 3: CREATE TAGSUBCATEGORIES ==========
        if (!empty($subcategoriesToCreate)) {
            $this->info("Batch 3: Create TagSubcategories (" . count($subcategoriesToCreate) . ")");
            
            $createSubcatData = [];
            foreach ($subcategoriesToCreate as $subcat) {
                $parentCat = $laravelTagCategories->firstWhere('IdClasificador', $subcat->IdClasificador);
                
                if ($parentCat && $parentCat->WooCommerceCategoryId) {
                    $createSubcatData[] = [
                        'name' => $subcat->Nombre,
                        'slug' => $this->generateSlug('subcat', $subcat->IdSubClasificador, $subcat->Nombre),
                        'parent' => $parentCat->WooCommerceCategoryId
                    ];
                }
            }
            
            try {
                $response = $this->wooCommerceService->batchCategories(['create' => $createSubcatData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $subcategoriesToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $subcategoriesToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " TagSubcategories");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create TagSubcategories");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data");
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $this->error("  Reason: Request timeout");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        // ========== BATCH 4: CREATE TAGS ==========
        if (!empty($tagsToCreate)) {
            $this->info("Batch 4: Create Tags (" . count($tagsToCreate) . ")");
            
            $createTagsData = [];
            foreach ($tagsToCreate as $tag) {
                $parentSubcat = $laravelTagSubcategories->firstWhere('IdSubClasificador', $tag->IdSubClasificador);
                
                if ($parentSubcat && $parentSubcat->WooCommerceCategoryId) {
                    $createTagsData[] = [
                        'name' => $tag->Nombre,
                        'slug' => $this->generateSlug('tag', $tag->IdTag, $tag->Nombre),
                        'parent' => $parentSubcat->WooCommerceCategoryId
                    ];
                }
            }
            
            try {
                $response = $this->wooCommerceService->batchCategories(['create' => $createTagsData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $tagsToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $tagsToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " Tags");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create Tags");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data");
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $this->error("  Reason: Request timeout");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        if (count($discrepancies) > 0) {
            $this->warn("Name differences found:");
            foreach ($discrepancies as $disc) {
                $this->line("  [{$disc['level']}] ID: {$disc['id']}");
                $this->line("    Laravel: {$disc['laravel_name']}");
                $this->line("    WooCommerce: {$disc['wc_name']}");
            }
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->info("✅ Synchronization completed in {$duration} seconds!");
        
        return Command::SUCCESS;
    }
    
    /**
     * Create full category hierarchy (TagCategories as root level)
     */
    protected function createFullHierarchy($laravelTagCategories, $laravelTagSubcategories, $laravelTags)
    {
        try {
            // Step 1: Batch create TagCategories (as root categories)
            $this->info("Step 1: Creating TagCategories...");
            
            $categoriesData = [];
            foreach ($laravelTagCategories as $cat) {
                $categoriesData[] = [
                    'name' => $cat->Nombre,
                    'slug' => $this->generateSlug('cat', $cat->IdClasificador, $cat->Nombre),
                    'parent' => 0  // Root level
                ];
            }
            
            try {
                $catResponse = $this->wooCommerceService->batchCategories(['create' => $categoriesData]);
                
                if (!isset($catResponse->create)) {
                    $this->error("✗ Failed to create TagCategories - Invalid response");
                    return;
                }
                
                // Save IDs to Laravel
                foreach ($catResponse->create as $index => $createdCat) {
                    $laravelTagCategories[$index]->WooCommerceCategoryId = $createdCat->id;
                    $laravelTagCategories[$index]->save();
                }
                
                $this->info("  ✓ Created " . count($catResponse->create) . " TagCategories");
            } catch (\Exception $e) {
                $this->error("✗ Error creating TagCategories: " . $e->getMessage());
                return;
            }
            
            // Step 2: Batch create TagSubcategories
            $this->info("Step 2: Creating TagSubcategories...");
            
            $subcategoriesData = [];
            foreach ($laravelTagSubcategories as $subcat) {
                $parentCat = $laravelTagCategories->firstWhere('IdClasificador', $subcat->IdClasificador);
                
                if (!$parentCat || !$parentCat->WooCommerceCategoryId) {
                    $this->warn("  Skipping subcategory '{$subcat->Nombre}' - Parent not found");
                    continue;
                }
                
                $subcategoriesData[] = [
                    'name' => $subcat->Nombre,
                    'slug' => $this->generateSlug('subcat', $subcat->IdSubClasificador, $subcat->Nombre),
                    'parent' => $parentCat->WooCommerceCategoryId
                ];
            }
            
            try {
                $subcatResponse = $this->wooCommerceService->batchCategories(['create' => $subcategoriesData]);
                
                if (!isset($subcatResponse->create)) {
                    $this->error("✗ Failed to create TagSubcategories - Invalid response");
                    return;
                }
                
                // Save IDs to Laravel
                foreach ($subcatResponse->create as $index => $createdSubcat) {
                    $laravelTagSubcategories[$index]->WooCommerceCategoryId = $createdSubcat->id;
                    $laravelTagSubcategories[$index]->save();
                }
                
                $this->info("  ✓ Created " . count($subcatResponse->create) . " TagSubcategories");
            } catch (\Exception $e) {
                $this->error("✗ Error creating TagSubcategories: " . $e->getMessage());
                return;
            }
            
            // Step 3: Batch create Tags
            $this->info("Step 3: Creating Tags...");
            
            $tagsData = [];
            foreach ($laravelTags as $tag) {
                $parentSubcat = $laravelTagSubcategories->firstWhere('IdSubClasificador', $tag->IdSubClasificador);
                
                if (!$parentSubcat || !$parentSubcat->WooCommerceCategoryId) {
                    $this->warn("  Skipping tag '{$tag->Nombre}' - Parent not found");
                    continue;
                }
                
                $tagsData[] = [
                    'name' => $tag->Nombre,
                    'slug' => $this->generateSlug('tag', $tag->IdTag, $tag->Nombre),
                    'parent' => $parentSubcat->WooCommerceCategoryId
                ];
            }
            
            try {
                $tagsResponse = $this->wooCommerceService->batchCategories(['create' => $tagsData]);
                
                if (!isset($tagsResponse->create)) {
                    $this->error("✗ Failed to create Tags - Invalid response");
                    return;
                }
                
                // Save IDs to Laravel
                foreach ($tagsResponse->create as $index => $createdTag) {
                    $laravelTags[$index]->WooCommerceCategoryId = $createdTag->id;
                    $laravelTags[$index]->save();
                }
                
                $this->info("  ✓ Created " . count($tagsResponse->create) . " Tags");
            } catch (\Exception $e) {
                $this->error("✗ Error creating Tags: " . $e->getMessage());
                return;
            }
            
            // Summary
            $this->newLine();
            $this->info("✅ Summary:");
            $this->info("  - TagCategories: " . count($catResponse->create));
            $this->info("  - TagSubcategories: " . count($subcatResponse->create));
            $this->info("  - Tags: " . count($tagsResponse->create));
            $this->info("  - Total API calls: 3");
            
        } catch (\Exception $e) {
            $this->error("✗ Critical error in hierarchy creation: " . $e->getMessage());
            $this->line("The synchronization was aborted to prevent data inconsistency");
        }
    }
    
    /**
     * Find category by slug in WooCommerce array
     */
    protected function findBySlug(array $categories, string $slug)
    {
        foreach ($categories as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }
        return null;
    }
    
    /**
     * Generate unique slug with pattern: prefix-id-name
     */
    protected function generateSlug(string $prefix, int $id, string $name): string
    {
        $slug = \Illuminate\Support\Str::slug($name);
        return "{$prefix}-{$id}-{$slug}";
    }
}