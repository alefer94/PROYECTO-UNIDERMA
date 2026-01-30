<?php

namespace App\Services\Sync;

use App\Models\Laboratory;
use App\Services\RestApiSyncService;

class LaboratorySyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return Laboratory::class;
    }

    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.laboratories');
    }

    protected function getFieldMapping(): array
    {
        return [
            'codLaboratorio' => 'CodLaboratorio', // API: camelCase → BD: PascalCase
            'nomLaboratorio' => 'NomLaboratorio',
            'flgNuevo' => 'FlgNuevo',
        ];
    }

    protected function getPrimaryKey(): string
    {
        return 'CodLaboratorio';
    }

    /**
     * Default parameters for laboratories endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => '002',
            'TipIndex' => 1,
        ];
    }
}
