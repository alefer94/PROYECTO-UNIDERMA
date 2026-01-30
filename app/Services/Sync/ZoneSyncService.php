<?php

namespace App\Services\Sync;

use App\Models\Zone;
use App\Services\RestApiSyncService;

class ZoneSyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return Zone::class;
    }

    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.zones');
    }

    protected function getFieldMapping(): array
    {
        return [
            'codZonal' => 'codZonal', // API: camelCase → BD: PascalCase
            'nombre' => 'nombre',
            'corta' => 'corta',
            'montoL' => 'montoL',
            'montoR' => 'montoR',
            'flgActivo' => 'flgActivo',
            'items' => 'items',
        ];
    }

    protected function getPrimaryKey(): string
    {
        return 'codZonal';
    }

    /**
     * Default parameters for zones endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => '002',
            'TipIndex' => 1,
        ];
    }

    protected function transformRecord(array $record, array $original): array
    {
        $fieldsToClean = [
            'codZonal', 'nombre', 'corta', 'montoL', 'montoR', 'flgActivo',
        ];

        foreach ($fieldsToClean as $field) {
            if (isset($record[$field]) && in_array($record[$field], ['', '_', '?'], true)) {
                $record[$field] = null;
            }
        }

        // Convertir items array a JSON string para la base de datos
        if (isset($record['items']) && is_array($record['items'])) {
            $record['items'] = json_encode($record['items']);
        }

        return $record;
    }

    protected function syncToDatabase(array $data): array
    {
        $result = parent::syncToDatabase($data);

        // Sincronizar items a la tabla zone_assignments
        foreach ($data as $item) {
            if (! empty($item['items'])) {
                $zone = Zone::find($item['codZonal']);
                if ($zone) {
                    // Decodificar el JSON de items si es string
                    $items = is_string($item['items'])
                        ? json_decode($item['items'], true)
                        : $item['items'];

                    if (is_array($items)) {
                        $zone->syncItemsFromArray($items);
                    }
                }
            }
        }

        return $result;
    }
}
