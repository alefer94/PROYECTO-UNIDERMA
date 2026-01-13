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
            'action' => 'required|string|in:sync_all',
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
}
