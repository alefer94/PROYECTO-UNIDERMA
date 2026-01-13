<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\LaboratorySyncService;
use App\Services\Sync\CatalogTypeSyncService;
use App\Services\Sync\CatalogCategorySyncService;
use App\Services\Sync\CatalogSubcategorySyncService;
use App\Services\Sync\TagCategorySyncService;
use App\Services\Sync\TagSubcategorySyncService;
use App\Services\Sync\TagSyncService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    /**
     * Endpoint principal: Sincronizar todas las entidades
     * 
     * POST /api/actions
     * Body: {
     *   "action": "sync_all",
     *   "data": {
     *     "params": {"Negocio": "002"}
     *   }
     * }
     */
    public function executeAction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:sync_all,sync_woocommerce',
            'data' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $action = $request->input('action');
        $data = $request->input('data', []);

        if ($action === 'sync_all') {
            return $this->syncAll($data);
        }

        if ($action === 'sync_woocommerce') {
            return $this->syncWooCommerce($data);
        }

        return response()->json([
            'success' => false,
            'message' => 'Acción no soportada'
        ], 400);
    }

    /**
     * Sincronizar todas las entidades con retry automático y transacción
     */
    private function syncAll(array $data)
    {
        $params = $data['params'] ?? [];
        $maxAttempts = 3;
        $attempt = 0;
        $lastError = null;
        
        // Orden correcto respetando dependencias
        $syncOrder = [
            'laboratories' => LaboratorySyncService::class,
            'catalog_types' => CatalogTypeSyncService::class,
            'tag_categories' => TagCategorySyncService::class,
            'catalog_categories' => CatalogCategorySyncService::class,
            'tag_subcategories' => TagSubcategorySyncService::class,
            'catalog_subcategories' => CatalogSubcategorySyncService::class,
            'tags' => TagSyncService::class,
            'products' => ProductSyncService::class,
        ];
        
        // Reintentar hasta 3 veces
        while ($attempt < $maxAttempts) {
            $attempt++;
            $results = [];
            $totalStats = ['inserted' => 0, 'updated' => 0, 'total' => 0];
            
            try {
                \Log::info("Sync attempt {$attempt}/{$maxAttempts}");
                
                // Siempre usar transacción
                \DB::beginTransaction();
                
                foreach ($syncOrder as $entity => $serviceClass) {
                    \Log::info("Syncing {$entity}...");
                    
                    $service = app($serviceClass);
                    $result = $service->sync($params);
                    
                    if (!$result['success']) {
                        throw new \Exception("Error syncing {$entity}: {$result['message']}");
                    }
                    
                    $results[$entity] = $result['stats'] ?? [];
                    
                    if (isset($result['stats'])) {
                        $totalStats['inserted'] += $result['stats']['inserted'] ?? 0;
                        $totalStats['updated'] += $result['stats']['updated'] ?? 0;
                        $totalStats['total'] += $result['stats']['total'] ?? 0;
                    }
                }
                
                // Si llegamos aquí, todo salió bien
                \DB::commit();
                
                \Log::info("Sync completed successfully on attempt {$attempt}");
                
                return response()->json([
                    'success' => true,
                    'message' => 'Sincronización completa exitosa',
                    'attempt' => $attempt,
                    'results' => $results,
                    'total_stats' => $totalStats,
                ]);
                
            } catch (\Exception $e) {
                // Rollback de la transacción
                \DB::rollBack();
                
                $lastError = $e->getMessage();
                
                \Log::warning("Sync failed on attempt {$attempt}/{$maxAttempts}", [
                    'error' => $lastError,
                    'completed' => array_keys($results),
                ]);
                
                // Si no es el último intento, esperar un poco antes de reintentar
                if ($attempt < $maxAttempts) {
                    sleep(2); // Esperar 2 segundos antes de reintentar
                }
            }
        }
        
        // Si llegamos aquí, fallaron todos los intentos
        \Log::error('Sync failed after all attempts', [
            'attempts' => $maxAttempts,
            'last_error' => $lastError,
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Falló la sincronización, inténtalo de nuevo',
            'error' => 'Se realizaron 3 intentos sin éxito',
            'last_error' => $lastError,
            'attempts' => $maxAttempts,
        ], 500);
    }

    /**
     * Sincronizar productos de Laravel a WooCommerce
     */
    private function syncWooCommerce(array $data)
    {
        try {
            $limit = $data['limit'] ?? null;
            $sku = $data['sku'] ?? null;
            
            \Log::info('Starting WooCommerce sync via API', [
                'limit' => $limit,
                'sku' => $sku,
            ]);
            
            // Ejecutar el comando de sincronización
            $exitCode = \Artisan::call('woocommerce:sync-products', array_filter([
                '--limit' => $limit,
                '--sku' => $sku,
            ]));
            
            // Obtener la salida del comando
            $output = \Artisan::output();
            
            if ($exitCode === 0) {
                // Parsear el output para extraer estadísticas
                $stats = $this->parseWooCommerceOutput($output);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Sincronización a WooCommerce completada exitosamente',
                    'stats' => $stats,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error en la sincronización a WooCommerce',
                    'error' => 'Command failed with exit code ' . $exitCode,
                ], 500);
            }
            
        } catch (\Exception $e) {
            \Log::error('WooCommerce sync failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error en la sincronización a WooCommerce',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse WooCommerce sync output to extract statistics
     */
    private function parseWooCommerceOutput(string $output): array
    {
        $stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        // Extraer números de la tabla de resultados
        if (preg_match('/Total Products\s+\|\s+(\d+)/', $output, $matches)) {
            $stats['total'] = (int) $matches[1];
        }
        if (preg_match('/Created\s+\|\s+(\d+)/', $output, $matches)) {
            $stats['created'] = (int) $matches[1];
        }
        if (preg_match('/Updated\s+\|\s+(\d+)/', $output, $matches)) {
            $stats['updated'] = (int) $matches[1];
        }
        if (preg_match('/Skipped.*?\|\s+(\d+)/', $output, $matches)) {
            $stats['skipped'] = (int) $matches[1];
        }
        if (preg_match('/Failed\s+\|\s+(\d+)/', $output, $matches)) {
            $stats['failed'] = (int) $matches[1];
        }

        return $stats;
    }
}
