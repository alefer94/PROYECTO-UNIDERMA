<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooCommerceService;
use App\Models\Laboratory;

class SyncWooCommerceLaboratories extends Command
{
    protected $signature = 'woocommerce:sync-laboratories';
    protected $description = 'Synchronize laboratories with WooCommerce categories';
    
    protected $wooCommerceService;
    
    public function __construct(WooCommerceService $wooCommerceService)
    {
        parent::__construct();
        $this->wooCommerceService = $wooCommerceService;
    }
    
    public function handle()
    {
        $startTime = microtime(true);
        
        $this->info('🚀 Starting laboratory synchronization...');
        
        // Obtener todas las categorías de WooCommerce con paginación
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
        
        // Buscar o crear categoría "Marcas" (nivel raíz)
        $marcasParent = null;
        foreach ($wcCategories as $category) {
            if ($category->slug === 'marcas' && $category->parent === 0) {
                $marcasParent = $category;
                break;
            }
        }
        
        if (!$marcasParent) {
            $this->warn("'Marcas' category not found - Creating it");
            
            try {
                $response = $this->wooCommerceService->batchCategories([
                    'create' => [
                        [
                            'name' => 'Marcas',
                            'slug' => 'marcas',
                            'parent' => 0
                        ]
                    ]
                ]);
                
                $marcasParent = $response->create[0];
                $this->info("Created 'Marcas' - ID: {$marcasParent->id}");
            } catch (\Exception $e) {
                $this->error("Failed to create 'Marcas' category: " . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $this->info("'Marcas' found - ID: {$marcasParent->id}");
        }
        
        // Traer datos de Laravel
        $laravelLaboratories = Laboratory::all();
        $this->info("Laravel Laboratories: " . count($laravelLaboratories));
        
        // Obtener laboratorios de WooCommerce (hijos de "Marcas")
        $wcLaboratories = [];
        foreach ($wcCategories as $category) {
            if ($category->parent === $marcasParent->id) {
                $wcLaboratories[] = $category;
            }
        }
        
        // Detectar laboratorios huérfanos (parent apunta a una categoría que no existe)
        $orphanedLabs = [];
        foreach ($wcCategories as $category) {
            // Verificar si el slug es de laboratorio pero el parent no es "Marcas"
            if (str_starts_with($category->slug, 'lab-') && $category->parent !== $marcasParent->id) {
                // Verificar que el parent no existe o no es "Marcas"
                $orphanedLabs[] = $category;
                $this->warn("  Found orphaned lab: {$category->name} (ID: {$category->id}, parent: {$category->parent})");
            }
        }
        
        // Re-enlazar laboratorios huérfanos a la nueva categoría "Marcas"
        if (count($orphanedLabs) > 0) {
            $this->warn("Found " . count($orphanedLabs) . " orphaned laboratories - Re-linking to 'Marcas'");
            
            $relinkData = [];
            foreach ($orphanedLabs as $orphan) {
                $relinkData[] = [
                    'id' => $orphan->id,
                    'parent' => $marcasParent->id
                ];
                // Agregar a la lista de labs de WC
                $wcLaboratories[] = $orphan;
            }
            
            try {
                $response = $this->wooCommerceService->batchCategories(['update' => $relinkData]);
                $this->info("  ✓ Re-linked " . count($response->update) . " orphaned laboratories");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to re-link orphaned labs: " . $e->getMessage());
            }
        }
        
        $this->info("WooCommerce Laboratories: " . count($wcLaboratories));
        
        // Enlazar laboratorios por slug
        $this->newLine();
        $this->info("=== LINKING LARAVEL RECORDS TO WOOCOMMERCE ===");
        
        $laravelWcIds = [];
        $laboratoriesToCreate = [];
        $linkedCount = 0;
        
        foreach ($laravelLaboratories as $lab) {
            if ($lab->WooCommerceCategoryId) {
                // Verificar que el ID existe en WooCommerce bajo la categoría correcta
                $existsInWc = $this->findById($wcLaboratories, $lab->WooCommerceCategoryId);
                
                if ($existsInWc) {
                    // El ID es válido
                    $laravelWcIds[] = $lab->WooCommerceCategoryId;
                } else {
                    // El ID no existe o está en otra categoría → Buscar por slug
                    $expectedSlug = $this->generateSlug($lab->CodLaboratorio);
                    $found = $this->findBySlug($wcLaboratories, $expectedSlug);
                    
                    if ($found) {
                        // Actualizar con el ID correcto
                        $lab->WooCommerceCategoryId = $found->id;
                        $lab->save();
                        $laravelWcIds[] = $found->id;
                        $linkedCount++;
                        $this->line("  Re-linked '{$lab->NomLaboratorio}' to WC ID: {$found->id}");
                    } else {
                        // No existe → Crear
                        $laboratoriesToCreate[] = $lab;
                    }
                }
            } else {
                // No tiene ID → Buscar por slug
                $expectedSlug = $this->generateSlug($lab->CodLaboratorio);
                $found = $this->findBySlug($wcLaboratories, $expectedSlug);
                
                if ($found) {
                    $lab->WooCommerceCategoryId = $found->id;
                    $lab->save();
                    $laravelWcIds[] = $found->id;
                    $linkedCount++;
                    $this->line("  Linked '{$lab->NomLaboratorio}' to WC ID: {$found->id}");
                } else {
                    $laboratoriesToCreate[] = $lab;
                }
            }
        }
        
        $this->info("Linked {$linkedCount} records");
        $this->info("Laboratories to create: " . count($laboratoriesToCreate));
        $this->info("Total WooCommerce IDs in Laravel: " . count($laravelWcIds));
        
        // Identificar huérfanos
        $orphanedLaboratories = [];
        $idsToDelete = [];
        
        foreach ($wcLaboratories as $wcLab) {
            if (!in_array($wcLab->id, $laravelWcIds)) {
                $orphanedLaboratories[] = $wcLab;
                $idsToDelete[] = $wcLab->id;
            }
        }
        
        $this->info("Orphaned laboratories in WooCommerce: " . count($orphanedLaboratories));
        
        if (count($orphanedLaboratories) > 0) {
            $this->warn("Laboratories to remove from WooCommerce:");
            foreach ($orphanedLaboratories as $orphan) {
                $this->line("  - ID: {$orphan->id}, Name: {$orphan->name}, Slug: {$orphan->slug}");
            }
        }
        
        // Comparar datos
        $this->newLine();
        $this->info("=== COMPARING LARAVEL AND WOOCOMMERCE DATA ===");
        
        $discrepancies = [];
        
        foreach ($laravelLaboratories as $laravelLab) {
            if ($laravelLab->WooCommerceCategoryId) {
                $wcLab = $this->findById($wcLaboratories, $laravelLab->WooCommerceCategoryId);
                
                if ($wcLab && $wcLab->name !== $laravelLab->NomLaboratorio) {
                    $discrepancies[] = [
                        'id' => $wcLab->id,
                        'laravel_name' => $laravelLab->NomLaboratorio,
                        'wc_name' => $wcLab->name,
                        'cod' => $laravelLab->CodLaboratorio
                    ];
                }
            }
        }
        
        $this->info("Found " . count($discrepancies) . " name discrepancies");
        
        // Construir batch data para actualizar
        $updateData = [];
        
        foreach ($discrepancies as $disc) {
            $updateData[] = [
                'id' => $disc['id'],
                'name' => $disc['laravel_name'],
                'slug' => $this->generateSlug($disc['cod'])
            ];
        }
        
        // Ejecutar batch operations
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
        
        // Crear laboratorios faltantes
        if (!empty($laboratoriesToCreate)) {
            $this->info("Batch 2: Create Laboratories (" . count($laboratoriesToCreate) . ")");
            
            $createData = [];
            foreach ($laboratoriesToCreate as $lab) {
                $createData[] = [
                    'name' => $lab->NomLaboratorio,
                    'slug' => $this->generateSlug($lab->CodLaboratorio),
                    'parent' => $marcasParent->id
                ];
            }
            
            try {
                $response = $this->wooCommerceService->batchCategories(['create' => $createData]);
                
                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $laboratoriesToCreate[$index]->WooCommerceCategoryId = $created->id;
                        $laboratoriesToCreate[$index]->save();
                    }
                    $this->info("  ✓ Created: " . count($response->create) . " Laboratories");
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->error("  ✗ Failed to create Laboratories");
                
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
                $this->line("  ID: {$disc['id']}");
                $this->line("    Laravel: {$disc['laravel_name']}");
                $this->line("    WooCommerce: {$disc['wc_name']}");
            }
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->info("✅ Synchronization completed in {$duration} seconds!");
        
        return Command::SUCCESS;
    }
    
    protected function findBySlug(array $categories, string $slug)
    {
        foreach ($categories as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }
        return null;
    }
    
    protected function findById(array $categories, int $id)
    {
        foreach ($categories as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }
        return null;
    }
    
    protected function generateSlug(string $codLaboratorio): string
    {
        return 'lab-' . strtolower($codLaboratorio);
    }
}
