<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class RestApiSyncService
{
    /**
     * Get the Eloquent model class name
     */
    abstract protected function getModel(): string;
    
    /**
     * Get the API endpoint URL
     */
    abstract protected function getEndpoint(): string;
    
    /**
     * Get field mapping from API to Database
     * 
     * @return array ['ApiField' => 'DbField']
     */
    abstract protected function getFieldMapping(): array;
    
    /**
     * Get the primary key field name
     */
    abstract protected function getPrimaryKey(): string;
    
    /**
     * Get default body parameters for the API request
     * Each service can override this to provide specific parameters
     * 
     * @return array
     */
    protected function getDefaultParams(): array
    {
        return [];
    }
    
    /**
     * Sync data from API to database
     * 
     * @param array $params Additional parameters to merge with defaults
     * @return array Result with success status, message and stats
     */
    public function sync(array $params = []): array
    {
        try {
            // Merge custom params with defaults
            $requestParams = array_merge($this->getDefaultParams(), $params);
            
            // Make API request
            $response = $this->makeApiRequest($requestParams);
            
            if (!$response['success']) {
                return $response;
            }
            
            // Transform data
            $data = $this->transformData($response['data']);
            
            // Sync to database
            $result = $this->syncToDatabase($data);
            
            return [
                'success' => true,
                'message' => 'Sincronización completada exitosamente',
                'stats' => $result,
            ];
            
        } catch (\Exception $e) {
            Log::error("Error syncing {$this->getModel()}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Error en la sincronización: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Make HTTP request to API
     */
    protected function makeApiRequest(array $params): array
    {
        $endpoint = $this->getEndpoint();
        
        if (empty($endpoint)) {
            return [
                'success' => false,
                'message' => 'Endpoint no configurado',
            ];
        }
        
        // Build HTTP request with timeout and retry
        $request = Http::timeout(config('api-sync.timeout', 30))
            ->retry(
                config('api-sync.retry_times', 3),
                config('api-sync.retry_delay', 100)
            );
        
        // Add authentication
        $request = $this->addAuthentication($request);
        
        // Make POST request
        $response = $request->post($endpoint, $params);
        
        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => "Error en petición API: HTTP {$response->status()}",
            ];
        }
        
        $data = $response->json();
        
        // Validate response structure
        if (!isset($data['data']) || !is_array($data['data'])) {
            return [
                'success' => false,
                'message' => 'Respuesta de API inválida: falta campo "data"',
            ];
        }
        
        Log::info("API Response for {$this->getModel()}", [
            'total_records' => count($data['data']),
            'endpoint' => $endpoint,
        ]);
        
        return [
            'success' => true,
            'data' => $data['data'],
            'message' => $data['mensaje'] ?? '',
        ];
    }
    
    /**
     * Add authentication to HTTP request
     */
    protected function addAuthentication($request)
    {
        $authType = config('api-sync.auth_type');
        
        switch ($authType) {
            case 'bearer':
                $token = config('api-sync.auth_token');
                if ($token) {
                    $request->withToken($token);
                }
                break;
                
            case 'api_key':
                $token = config('api-sync.auth_token');
                $header = config('api-sync.api_key_header', 'X-API-Key');
                if ($token && $header) {
                    $request->withHeaders([$header => $token]);
                }
                break;
                
            case 'none':
            default:
                // No authentication
                break;
        }
        
        return $request;
    }
    
    /**
     * Transform API data to database format
     */
    protected function transformData(array $apiData): array
    {
        $mapping = $this->getFieldMapping();
        $transformed = [];
        
        foreach ($apiData as $item) {
            $record = [];
            
            foreach ($mapping as $apiField => $dbField) {
                $record[$dbField] = $item[$apiField] ?? null;
            }
            
            // Allow custom transformation
            $record = $this->transformRecord($record, $item);
            
            $transformed[] = $record;
        }
        
        return $transformed;
    }
    
    /**
     * Transform individual record (can be overridden)
     * 
     * @param array $record Transformed record
     * @param array $original Original API data
     * @return array
     */
    protected function transformRecord(array $record, array $original): array
    {
        return $record;
    }
    
    /**
     * Sync data to database using upsert
     */
    protected function syncToDatabase(array $data): array
    {
        if (empty($data)) {
            return [
                'inserted' => 0,
                'updated' => 0,
                'total' => 0,
            ];
        }
        
        $model = $this->getModel();
        $primaryKey = $this->getPrimaryKey();
        
        $beforeCount = $model::count();
        
        // Upsert data
        $model::upsert(
            $data,
            [$primaryKey],
            array_keys($data[0])
        );
        
        $afterCount = $model::count();
        $inserted = $afterCount - $beforeCount;
        $updated = count($data) - $inserted;
        
        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => count($data),
        ];
    }
}
