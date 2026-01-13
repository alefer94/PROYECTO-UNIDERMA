<?php

namespace App\Services\Sync;

use App\Models\CatalogCategory;
use App\Services\RestApiSyncService;

class CatalogCategorySyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return CatalogCategory::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.catalog_categories');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API (camelCase) → Database (PascalCase)
            'codClasificador' => 'CodClasificador',
            'codTipcat' => 'CodTipcat',
            'nombre' => 'Nombre',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'CodClasificador';
    }
    
    /**
     * Default parameters for catalog category endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => '002',
            'TipIndex' => 1,
        ];
    }
}
