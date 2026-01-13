<?php

namespace App\Services\Sync;

use App\Models\CatalogType;
use App\Services\RestApiSyncService;

class CatalogTypeSyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return CatalogType::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.catalog_types');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API (camelCase) → Database (PascalCase)
            'codTipcat' => 'Tipcat',
            'nombre' => 'Nombre',
            'idEstructura' => 'IdEstructura',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'Tipcat';
    }
    
    /**
     * Default parameters for catalog types endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => '002',
            'TipIndex' => 1,
        ];
    }
}
