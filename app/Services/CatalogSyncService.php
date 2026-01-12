<?php

namespace App\Services;

use App\Models\Catalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CatalogSyncService
{
    /**
     * Sincroniza catálogos desde la API externa.
     *
     * @param  array  $params  Parámetros para el POST request
     * @return array Resultado de la sincronización
     */
    public function sync(array $params = []): array
    {
        try {
            // Merge con parámetros por defecto
            $requestParams = array_merge(
                config('catalog-sync.default_params'),
                $params
            );

            // Realizar petición a la API
            $response = $this->makeApiRequest($requestParams);

            if (! $response['success']) {
                return $response;
            }

            // Transformar y sincronizar datos
            $catalogs = $this->transformData($response['data']);
            $result = $this->syncToDatabase($catalogs);

            return [
                'success' => true,
                'message' => 'Sincronización completada exitosamente',
                'stats' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('Error en sincronización de catálogos', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'message' => 'Error en la sincronización: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Realiza la petición HTTP a la API externa.
     */
    protected function makeApiRequest(array $params): array
    {
        $apiUrl = config('catalog-sync.api_url');

        if (empty($apiUrl)) {
            return [
                'success' => false,
                'message' => 'URL de API no configurada. Configure CATALOG_SYNC_API_URL en .env',
            ];
        }

        // Construir request con autenticación
        $request = Http::timeout(config('catalog-sync.timeout'))
            ->retry(
                config('catalog-sync.retry_times'),
                config('catalog-sync.retry_delay')
            );

        // Agregar autenticación según configuración
        $request = $this->addAuthentication($request);

        // Realizar POST request
        $response = $request->post($apiUrl, $params);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => 'Error en la petición a la API: '.$response->status(),
            ];
        }

        $data = $response->json();

        // Validar estructura de respuesta
        if (! isset($data['data']) || ! is_array($data['data'])) {
            return [
                'success' => false,
                'message' => 'Respuesta de API inválida: falta campo "data"',
            ];
        }

        // Log para debug
        Log::info('API Response', [
            'mensaje' => $data['mensaje'] ?? '',
            'total_records' => count($data['data']),
            'first_record' => $data['data'][0] ?? null,
        ]);

        return [
            'success' => true,
            'data' => $data['data'],
            'message' => $data['mensaje'] ?? '',
        ];
    }

    /**
     * Agrega autenticación al request HTTP.
     *
     * @param  \Illuminate\Http\Client\PendingRequest  $request
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function addAuthentication($request)
    {
        $authType = config('catalog-sync.auth_type');

        switch ($authType) {
            case 'bearer':
                $token = config('catalog-sync.auth_token');
                if ($token) {
                    $request->withToken($token);
                }
                break;

            case 'api_key':
                $token = config('catalog-sync.auth_token');
                $header = config('catalog-sync.api_key_header');
                if ($token && $header) {
                    $request->withHeaders([$header => $token]);
                }
                break;

            case 'none':
            default:
                // Sin autenticación
                break;
        }

        return $request;
    }

    /**
     * Transforma los datos de la API al formato de la base de datos.
     */
    protected function transformData(array $apiData): array
    {
        $fieldMapping = config('catalog-sync.field_mapping');
        $transformed = [];

        foreach ($apiData as $item) {
            $catalog = [];

            foreach ($fieldMapping as $apiField => $dbField) {
                $catalog[$dbField] = $item[$apiField] ?? null;
            }

            $transformed[] = $catalog;
        }

        return $transformed;
    }

    /**
     * Sincroniza los datos transformados a la base de datos.
     *
     * @return array Estadísticas de la sincronización
     */
    protected function syncToDatabase(array $catalogs): array
    {
        $strategy = config('catalog-sync.sync_strategy');

        switch ($strategy) {
            case 'replace':
                return $this->replaceAll($catalogs);

            case 'insert':
                return $this->insertOnly($catalogs);

            case 'upsert':
            default:
                return $this->upsertCatalogs($catalogs);
        }
    }

    /**
     * Estrategia UPSERT: actualiza existentes e inserta nuevos.
     */
    protected function upsertCatalogs(array $catalogs): array
    {
        if (empty($catalogs)) {
            return ['inserted' => 0, 'updated' => 0, 'total' => 0];
        }

        $beforeCount = Catalog::count();

        // Upsert usando codCatalogo como unique key
        Catalog::upsert(
            $catalogs,
            ['codCatalogo'], // Unique key
            array_keys($catalogs[0]) // Campos a actualizar
        );

        $afterCount = Catalog::count();
        $inserted = $afterCount - $beforeCount;
        $updated = count($catalogs) - $inserted;

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => count($catalogs),
        ];
    }

    /**
     * Estrategia INSERT: solo inserta registros nuevos.
     */
    protected function insertOnly(array $catalogs): array
    {
        $inserted = 0;

        foreach ($catalogs as $catalog) {
            $exists = Catalog::where('codCatalogo', $catalog['codCatalogo'])->exists();

            if (! $exists) {
                Catalog::create($catalog);
                $inserted++;
            }
        }

        return [
            'inserted' => $inserted,
            'skipped' => count($catalogs) - $inserted,
            'total' => count($catalogs),
        ];
    }

    /**
     * Estrategia REPLACE: elimina todos y los reemplaza.
     */
    protected function replaceAll(array $catalogs): array
    {
        DB::transaction(function () use ($catalogs) {
            Catalog::truncate();
            Catalog::insert($catalogs);
        });

        return [
            'replaced' => count($catalogs),
            'total' => count($catalogs),
        ];
    }
}
