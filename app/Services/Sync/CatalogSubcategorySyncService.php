<?php

namespace App\Services\Sync;

use App\Models\CatalogSubcategory;
use App\Services\RestApiSyncService;

class CatalogSubcategorySyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return CatalogSubcategory::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.catalog_subcategories');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API (camelCase) → Database (PascalCase)
            'codSubClasificador' => 'CodSubClasificador',
            'codTipcat' => 'CodTipcat',
            'codClasificador' => 'CodClasificador',
            'nombre' => 'Nombre',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'CodSubClasificador';
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
