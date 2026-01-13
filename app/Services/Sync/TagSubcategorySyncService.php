<?php

namespace App\Services\Sync;

use App\Models\TagSubcategory;
use App\Services\RestApiSyncService;

class TagSubcategorySyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return TagSubcategory::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.tag_subcategories');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API (camelCase) → Database (PascalCase)
            'idSubClasificador' => 'IdSubClasificador',
            'idClasificador' => 'IdClasificador',
            'nombre' => 'Nombre',
            'corta' => 'Corta',
            'flgActivo' => 'FlgActivo',
            'orden' => 'Orden',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'IdSubClasificador';
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
