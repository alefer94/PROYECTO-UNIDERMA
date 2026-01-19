<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooCommerceService;
use App\Models\CatalogType;
use App\Models\CatalogCategory;
use App\Models\CatalogSubcategory;

class SyncWooCommerceCatalogCategories extends Command
{
    protected $signature = 'woocommerce:sync-catalog-categories';
    protected $description = 'Sync catalog categories to WooCommerce';

    protected WooCommerceService $woocommerceService;

    public function __construct(WooCommerceService $woocommerceService)
    {
        parent::__construct();
        $this->woocommerceService = $woocommerceService;
    }

    public function handle()
    {
        $startTime = microtime(true);

        $this->info('Starting catalog categories synchronization...');

        $page = 1;
        $wcCategories = [];

        try{
            do{
                $categories = $this->woocommerceService->getCategories([
                    'per_page' => 100,
                    'page' => $page,
                ]);

                $wcCategories = array_merge($wcCategories, $categories);
                $page++;
            } while (count($categories) === 100);

            $this->info("Total categories fetched: " . count($wcCategories));
        } catch(\Exception $e){
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
        
        $laravelCatalogTypes = CatalogType::all();
        $laravelCatalogCategories = CatalogCategory::all();
        $laravelCatalogSubcategories = CatalogSubcategory::all();

        $this->info("Total catalog types: " . count($laravelCatalogTypes));
        $this->info("Total catalog categories: " . count($laravelCatalogCategories));
        $this->info("Total catalog subcategories: " . count($laravelCatalogSubcategories));

        $catalogTypes = [];
        $catalogCategories = [];
        $catalogSubcategories = [];

        foreach ($wcCategories as $category) {
            if(str_starts_with($category->slug,'type-')){
                $catalogTypes[] = $category;
            }
        }

        foreach ($wcCategories as $category){
            if(str_starts_with($category->slug,'typecat-')){
                $catalogCategories[] = $category;
            }
        }

        foreach ($wcCategories as $category){
            if(str_starts_with($category->slug,'typesub-')){
                $catalogSubcategories[] = $category;
            }
        }

        $this->info("Catalog Types: " . count($catalogTypes));
        $this->info("Catalog Categories: " . count($catalogCategories));
        $this->info("Catalog Subcategories: " . count($catalogSubcategories));

        $this->newLine();
        $this->info("=== DETECTING ORPHANED CATEGORIES ===");
        
        $orphanedCatalogTypes = [];
        $orphanedCatalogCategories = [];
        $orphanedCatalogSubcategories = [];

        // Detectar CatalogTypes huérfanas (parent != 0, deben ser raíz)
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'type-') && $category->parent !== 0) {
                $orphanedCatalogTypes[] = $category;
                $this->warn("  Found orphaned CatalogType: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Detectar CatalogCategories huérfanas (parent no es un CatalogType válido)
        $validCatalogIds = array_map(fn($cat) => $cat->id, $catalogTypes);
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'typecat-') && !in_array($category->parent, $validCatalogIds)) {
                $orphanedCatalogCategories[] = $category;
                $this->warn("  Found orphaned CatalogCategory: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Detectar CatalogSubcategories huérfanas (parent no es un CatalogCategory válido)
        $validSubcatalogIds = array_map(fn($subcat) => $subcat->id, $catalogCategories);
        foreach ($wcCategories as $category) {
            if (str_starts_with($category->slug, 'typesub-') && !in_array($category->parent, $validSubcatalogIds)) {
                $orphanedCatalogSubcategories[] = $category;
                $this->warn("  Found orphaned CatalogSubcategory: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }

        $relinkData = [];

        foreach ($orphanedCatalogTypes as $orphan) {
            $relinkData[] = [
                'id' => $orphan->id,
                'parent' => 0,
            ];
            
            $catalogTypes[] = $orphan;
        }

        foreach ($orphanedCatalogCategories as $orphan){
            preg_match('/typecat-(\d+)-/',$orphan->slug,$matches);
            if(isset($matches[1])){
                $idCatalogCategory = (int)$matches[1];
                $laravelCatalogCat = $laravelCatalogCategories->firstWhere('CodClasificador',$idCatalogCategory);
                
                if($laravelCatalogCat){
                    $parentCatalogType = $laravelCatalogTypes->firstWhere('CodTipcat',$laravelCatalogCat->CodTipcat);
                    if($parentCatalogType && $parentCatalogType->WooCommerceCategoryId){
                        $relinkData[] = [
                            'id' => $orphan->id,
                            'parent' => $parentCatalogType->WooCommerceCategoryId,
                        ];
                        $catalogCategories[] = $orphan;
                    }
                }
            }
        }

        foreach ($orphanedCatalogSubcategories as $orphan){
            preg_match('/typesub-(\d+)-/',$orphan->slug,$matches);
            if(isset($matches[1])){
                $idCatalogSubcategory = (int)$matches[1];
                $laravelCatalogSubcat = $laravelCatalogSubcategories->firstWhere('CodSubClasificador',$idCatalogSubcategory);
                
                if($laravelCatalogSubcat){
                    $parentCatalogCategory = $laravelCatalogCategories->firstWhere('CodClasificador',$laravelCatalogSubcat->CodClasificador);
                    if($parentCatalogCategory && $parentCatalogCategory->WooCommerceCategoryId){
                        $relinkData[] = [
                            'id' => $orphan->id,
                            'parent' => $parentCatalogCategory->WooCommerceCategoryId,
                        ];
                        $catalogSubcategories[] = $orphan;
                    }
                }
            }
        }

        if(!empty($relinkData)){
            $this->info("Re-linking ".count($relinkData)."orphaned categories to correct parents");

            try{
                $response = $this->wooCommerceService->batchCategories(['update'=> $relinkData]);
                $this->info(" Re-linked ".count($response->update)." categories");
            }catch(Exception $e){
                $this->error("Failed to re-link categories: ".$e->getMessage());
                return Command::FAILURE;
            }
        }else{
            $this->info("No orphaned categories found.");
        }

        //Obtener todos los WooCommerceCategoryId de las categorias
        $laravelWcIds = [];
        $CatalogsTypeToCreate = [];
        $CatalogsCategoryToCreate = [];
        $CatalogsSubcategoryToCreate = [];

        $linkedCount = 0;

        $this->newLine();
        $this->info("=== LINKING LARAVEL RECORDS TO WOOCOMMMERCE ===");

        foreach ($laravelCatalogTypes as $catType){
            if($catType->WooCommerceCategoryId){
                // Verify that the ID exists in WooCommerce
                $existsInWc = $this->findById($catalogTypes, $catType->WooCommerceCategoryId);
                
                if ($existsInWc) {
                    // The ID is valid
                    $laravelWcIds[] = $catType->WooCommerceCategoryId;
                } else {
                    // The ID doesn't exist → Search by slug or create
                    $expectedSlug = $this->generateSlug('type-',$catType->Tipcat,$catType->Nombre);
                    $found = $this->findBySlug($catalogTypes,$expectedSlug);
                    
                    if($found){
                        // Update with the correct ID
                        $catType->WooCommerceCategoryId = $found->id;
                        $catType->save();
                        $laravelWcIds[] = $found->id;
                        $linkedCount++;
                        $this->info("  Re-linked CatalogType '{$catType->Nombre}' to WC ID: {$found->id}");
                    }else{
                        // Doesn't exist → Create
                        $CatalogsTypeToCreate[] = $catType;
                    }
                }
            }else{
                // No ID → Search by slug
                $expectedSlug = $this->generateSlug('type-',$catType->Tipcat,$catType->Nombre);
                $found = $this->findBySlug($catalogTypes,$expectedSlug);
                if($found){
                    $catType->WooCommerceCategoryId = $found->id;
                    $catType->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->info("  Linked CatalogType '{$catType->Nombre}' to WC ID: {$found->id}");
                }else{
                    $CatalogsTypeToCreate[] = $catType;
                }
            }
        }

        //Procesar CatalogsCategories

        foreach ($laravelCatalogCategories as $catalogCat){
            if($catalogCat->WooCommerceCategoryId){
                // Verify that the ID exists in WooCommerce
                $existsInWc = $this->findById($catalogCategories, $catalogCat->WooCommerceCategoryId);
                
                if ($existsInWc) {
                    // The ID is valid
                    $laravelWcIds[] = $catalogCat->WooCommerceCategoryId;
                } else {
                    // The ID doesn't exist → Search by slug or create
                    $expectedSlug = $this->generateSlug('typecat-',$catalogCat->CodClasificador,$catalogCat->Nombre);
                    $found = $this->findBySlug($catalogCategories,$expectedSlug);
                    
                    if($found){
                        // Update with the correct ID
                        $catalogCat->WooCommerceCategoryId = $found->id;
                        $catalogCat->save();
                        $laravelWcIds[] = $found->id;
                        $linkedCount++;
                        $this->info("  Re-linked CatalogCategory '{$catalogCat->Nombre}' to WC ID: {$found->id}");
                    }else{
                        // Doesn't exist → Create
                        $CatalogsCategoryToCreate[] = $catalogCat;
                    }
                }
            }else{
                // No ID → Search by slug
                $expectedSlug = $this->generateSlug('typecat-',$catalogCat->CodClasificador,$catalogCat->Nombre);
                $found = $this->findBySlug($catalogCategories,$expectedSlug);

                if($found){
                    $catalogCat->WooCommerceCategoryId = $found->id;
                    $catalogCat->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->info("  Linked CatalogCategory '{$catalogCat->Nombre}' to WC ID: {$found->id}");
                }else{
                    $CatalogsCategoryToCreate[] = $catalogCat;
                }
            }
        }

        foreach ($laravelCatalogSubcategories as $catalogSubcat){
            if($catalogSubcat->WooCommerceCategoryId){
                // Verify that the ID exists in WooCommerce
                $existsInWc = $this->findById($catalogSubcategories, $catalogSubcat->WooCommerceCategoryId);
                
                if ($existsInWc) {
                    // The ID is valid
                    $laravelWcIds[] = $catalogSubcat->WooCommerceCategoryId;
                } else {
                    // The ID doesn't exist → Search by slug or create
                    $expectedSlug = $this->generateSlug('typesub-',$catalogSubcat->CodSubClasificador,$catalogSubcat->Nombre);
                    $found = $this->findBySlug($catalogSubcategories,$expectedSlug);
                    
                    if($found){
                        // Update with the correct ID
                        $catalogSubcat->WooCommerceCategoryId = $found->id;
                        $catalogSubcat->save();
                        $laravelWcIds[] = $found->id;
                        $linkedCount++;
                        $this->info("  Re-linked CatalogSubcategory '{$catalogSubcat->Nombre}' to WC ID: {$found->id}");
                    }else{
                        // Doesn't exist → Create
                        $CatalogsSubcategoryToCreate[] = $catalogSubcat;
                    }
                }
            }else{
                // No ID → Search by slug
                $expectedSlug = $this->generateSlug('typesub-',$catalogSubcat->CodSubClasificador,$catalogSubcat->Nombre);
                $found = $this->findBySlug($catalogSubcategories,$expectedSlug);

                if($found){
                    $catalogSubcat->WooCommerceCategoryId = $found->id;
                    $catalogSubcat->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->info("  Linked CatalogSubcategory '{$catalogSubcat->Nombre}' to WC ID: {$found->id}");
                }else{
                    $CatalogsSubcategoryToCreate[] = $catalogSubcat;
                }
            }
        }

        $this->info("Linked: {$linkedCount} records");
        $this->info("Catalog Types to create: " . count($CatalogsTypeToCreate));
        $this->info("Catalog Categories to create: " . count($CatalogsCategoryToCreate));
        $this->info("Catalog Subcategories to create: " . count($CatalogsSubcategoryToCreate));

        $this->info("Total WooCommerce IDs in Laravel: " . count($laravelWcIds));
        
        // Identificar categorías huérfanas con lógica en cascada
        $orphanedCategories = [];
        $orphanedIds = [];

        // Nivel 1: CatalogType huérfanos
        foreach ($catalogTypes as $wcType) {
            if (!in_array($wcType->id, $laravelWcIds)) {
                $orphanedCategories[] = $wcType;
                $orphanedIds[] = $wcType->id;
                
                // Marcar todos sus descendientes como huérfanos en cascada
                foreach ($catalogCategories as $wcCat) {
                    if ($wcCat->parent === $wcType->id && !in_array($wcCat->id, $orphanedIds)) {
                        $orphanedCategories[] = $wcCat;
                        $orphanedIds[] = $wcCat->id;
                        
                        // Marcar todos los subcategories hijos de esta category
                        foreach ($catalogSubcategories as $wcSubcat) {
                            if ($wcSubcat->parent === $wcCat->id && !in_array($wcSubcat->id, $orphanedIds)) {
                                $orphanedCategories[] = $wcSubcat;
                                $orphanedIds[] = $wcSubcat->id;
                            }
                        }
                    }
                }
            }
        }
        
        // Nivel 2: CatalogCategory huérfanos (que no fueron marcados en cascada)
        foreach ($catalogCategories as $wcCat) {
            if (!in_array($wcCat->id, $laravelWcIds) && !in_array($wcCat->id, $orphanedIds)) {
                $orphanedCategories[] = $wcCat;
                $orphanedIds[] = $wcCat->id;
                
                // Marcar todos sus subcategories hijos como huérfanos
                foreach ($catalogSubcategories as $wcSubcat) {
                    if ($wcSubcat->parent === $wcCat->id && !in_array($wcSubcat->id, $orphanedIds)) {
                        $orphanedCategories[] = $wcSubcat;
                        $orphanedIds[] = $wcSubcat->id;
                    }
                }
            }
        }
        
        // Nivel 3: CatalogSubcategory huérfanos (que no fueron marcados en cascada)
        foreach ($catalogSubcategories as $wcSubcat) {
            if (!in_array($wcSubcat->id, $laravelWcIds) && !in_array($wcSubcat->id, $orphanedIds)) {
                $orphanedCategories[] = $wcSubcat;
                $orphanedIds[] = $wcSubcat->id;
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
        
        // Combinar todos los descendientes
        $descendants = array_merge($catalogTypes, $catalogCategories, $catalogSubcategories);
        
        $this->info("Total categories in hierarchy: " . count($descendants));
        
        // Filtrar $wcCategories para mantener solo los descendientes
        $validIds = array_map(fn($cat) => $cat->id, $descendants);
        $wcCategories = array_filter($wcCategories, fn($cat) => in_array($cat->id, $validIds));
        
        $this->info("Filtered categories: " . count($wcCategories) . " remaining");
        
        // Comparar datos entre Laravel y WooCommerce
        $this->newLine();
        $this->info("=== COMPARING LARAVEL AND WOOCOMMERCE DATA ===");
        
        $discrepancies = [];
        
        // Comparar CatalogType
        foreach ($laravelCatalogTypes as $laravelType) {
            if ($laravelType->WooCommerceCategoryId) {
                $wcType = null;
                foreach ($catalogTypes as $wc) {
                    if ($wc->id === $laravelType->WooCommerceCategoryId) {
                        $wcType = $wc;
                        break;
                    }
                }
                
                if ($wcType && $wcType->name !== $laravelType->Nombre) {
                    $discrepancies[] = [
                        'level' => 'CatalogType',
                        'id' => $wcType->id,
                        'laravel_name' => $laravelType->Nombre,
                        'wc_name' => $wcType->name,
                    ];
                }
            }
        }
        
        // Comparar CatalogCategory
        foreach ($laravelCatalogCategories as $laravelCat) {
            if ($laravelCat->WooCommerceCategoryId) {
                $wcCat = null;
                foreach ($catalogCategories as $wc) {
                    if ($wc->id === $laravelCat->WooCommerceCategoryId) {
                        $wcCat = $wc;
                        break;
                    }
                }
                
                if ($wcCat && $wcCat->name !== $laravelCat->Nombre) {
                    $discrepancies[] = [
                        'level' => 'CatalogCategory',
                        'id' => $wcCat->id,
                        'laravel_name' => $laravelCat->Nombre,
                        'wc_name' => $wcCat->name,
                    ];
                }
            }
        }
        
        // Comparar CatalogSubcategory
        foreach ($laravelCatalogSubcategories as $laravelSubcat) {
            if ($laravelSubcat->WooCommerceCategoryId) {
                $wcSubcat = null;
                foreach ($catalogSubcategories as $wc) {
                    if ($wc->id === $laravelSubcat->WooCommerceCategoryId) {
                        $wcSubcat = $wc;
                        break;
                    }
                }
                
                if ($wcSubcat && $wcSubcat->name !== $laravelSubcat->Nombre) {
                    $discrepancies[] = [
                        'level' => 'CatalogSubcategory',
                        'id' => $wcSubcat->id,
                        'laravel_name' => $laravelSubcat->Nombre,
                        'wc_name' => $wcSubcat->name,
                    ];
                }
            }
        }
        
        $this->info("Found " . count($discrepancies) . " name discrepancies");
        
        // Separar discrepancias por nivel
        $typesToUpdate = [];
        $categoriesToUpdate = [];
        $subcategoriesToUpdate = [];
        
        foreach ($discrepancies as $disc) {
            if ($disc['level'] === 'CatalogType') {
                $typesToUpdate[] = $disc;
            } elseif ($disc['level'] === 'CatalogCategory') {
                $categoriesToUpdate[] = $disc;
            } elseif ($disc['level'] === 'CatalogSubcategory') {
                $subcategoriesToUpdate[] = $disc;
            }
        }
        
        $this->info("Types to update: " . count($typesToUpdate));
        $this->info("Categories to update: " . count($categoriesToUpdate));
        $this->info("Subcategories to update: " . count($subcategoriesToUpdate));

        // Construir batch data para actualizar
        $updateData = [];
        
        foreach ($typesToUpdate as $type) {
            $laravelType = $laravelCatalogTypes->firstWhere('WooCommerceCategoryId', $type['id']);
            
            $updateData[] = [
                'id' => $type['id'],
                'name' => $type['laravel_name'],
                'slug' => $this->generateSlug('type', $laravelType->Tipcat, $type['laravel_name'])
            ];
        }
        
        foreach ($categoriesToUpdate as $cat) {
            $laravelCat = $laravelCatalogCategories->firstWhere('WooCommerceCategoryId', $cat['id']);
            
            $updateData[] = [
                'id' => $cat['id'],
                'name' => $cat['laravel_name'],
                'slug' => $this->generateSlug('typecat', $laravelCat->CodClasificador, $cat['laravel_name'])
            ];
        }
        
        foreach ($subcategoriesToUpdate as $subcat) {
            $laravelSubcat = $laravelCatalogSubcategories->firstWhere('WooCommerceCategoryId', $subcat['id']);
            
            $updateData[] = [
                'id' => $subcat['id'],
                'name' => $subcat['laravel_name'],
                'slug' => $this->generateSlug('typesub', $laravelSubcat->CodSubClasificador, $subcat['laravel_name'])
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
                $response = $this->woocommerceService->batchCategories($batchData);
                
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
        
        // ========== BATCH 2: CREATE CATALOG TYPES ==========
        if (!empty($CatalogsTypeToCreate)) {
            $this->info("Batch 2: Create CatalogTypes (" . count($CatalogsTypeToCreate) . ")");
            
            $createTypeData = [];
            foreach ($CatalogsTypeToCreate as $type) {
                $createTypeData[] = [
                    'name' => $type->Nombre,
                    'slug' => $this->generateSlug('type', $type->Tipcat, $type->Nombre),
                    'parent' => 0  // CatalogTypes son categorías raíz
                ];
            }
            
            try {
                $response = $this->woocommerceService->batchCategories(['create' => $createTypeData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $CatalogsTypeToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $CatalogsTypeToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " CatalogTypes");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create CatalogTypes");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data");
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $this->error("  Reason: Request timeout");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        // ========== BATCH 3: CREATE CATALOG CATEGORIES ==========
        if (!empty($CatalogsCategoryToCreate)) {
            $this->info("Batch 3: Create CatalogCategories (" . count($CatalogsCategoryToCreate) . ")");
            
            $createCatData = [];
            foreach ($CatalogsCategoryToCreate as $cat) {
                $parentType = $laravelCatalogTypes->firstWhere('Tipcat', $cat->CodTipcat);
                
                if ($parentType && $parentType->WooCommerceCategoryId) {
                    $createCatData[] = [
                        'name' => $cat->Nombre,
                        'slug' => $this->generateSlug('typecat', $cat->CodClasificador, $cat->Nombre),
                        'parent' => $parentType->WooCommerceCategoryId
                    ];
                }
            }
            
            try {
                $response = $this->woocommerceService->batchCategories(['create' => $createCatData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $CatalogsCategoryToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $CatalogsCategoryToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " CatalogCategories");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create CatalogCategories");
                
                if (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
                    $this->error("  Reason: WooCommerce returned invalid data");
                } elseif (str_contains($errorMessage, 'timed out')) {
                    $this->error("  Reason: Request timeout");
                } else {
                    $this->error("  Reason: " . $errorMessage);
                }
            }
        }
        
        // ========== BATCH 4: CREATE CATALOG SUBCATEGORIES ==========
        if (!empty($CatalogsSubcategoryToCreate)) {
            $this->info("Batch 4: Create CatalogSubcategories (" . count($CatalogsSubcategoryToCreate) . ")");
            
            $createSubcatData = [];
            foreach ($CatalogsSubcategoryToCreate as $subcat) {
                $parentCat = $laravelCatalogCategories->firstWhere('CodClasificador', $subcat->CodClasificador);
                
                if ($parentCat && $parentCat->WooCommerceCategoryId) {
                    $createSubcatData[] = [
                        'name' => $subcat->Nombre,
                        'slug' => $this->generateSlug('typesub', $subcat->CodSubClasificador, $subcat->Nombre),
                        'parent' => $parentCat->WooCommerceCategoryId
                    ];
                }
            }
            
            try {
                $response = $this->woocommerceService->batchCategories(['create' => $createSubcatData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $CatalogsSubcategoryToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $CatalogsSubcategoryToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " CatalogSubcategories");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create CatalogSubcategories");
                
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
     * Find category by ID in WooCommerce array
     */
    protected function findById(array $categories, int $id)
    {
        foreach ($categories as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }
        return null;
    }
    
    /**
     * Generate unique slug with pattern: prefix-id-name
     */
    protected function generateSlug(string $prefix, $id, string $name): string
    {
        $slug = \Illuminate\Support\Str::slug($name);
        return "{$prefix}-{$id}-{$slug}";
    }
}