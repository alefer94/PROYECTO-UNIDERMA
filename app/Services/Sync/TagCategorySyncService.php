<?php

namespace App\Services\Sync;

use App\Models\TagCategory;
use App\Services\RestApiSyncService;

class TagCategorySyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return TagCategory::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.tag_categories');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API (camelCase) → Database (PascalCase)
            'idClasificador' => 'IdClasificador',
            'nombre' => 'Nombre',
            'corta' => 'Corta',
            'flgActivo' => 'FlgActivo',
            'orden' => 'Orden',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'IdClasificador';
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
