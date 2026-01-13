<?php

namespace App\Services\Sync;

use App\Models\Tag;
use App\Services\RestApiSyncService;

class TagSyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return Tag::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.tags');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            'idTag' => 'IdTag',                         // API: camelCase → BD: PascalCase
            'idClasificador' => 'IdClasificador',
            'idSubClasificador' => 'IdSubClasificador',
            'nombre' => 'Nombre',
            'corta' => 'Corta',
            'flgActivo' => 'FlgActivo',
            'orden' => 'Orden',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'IdTag';
    }
    
    /**
     * Default parameters for tags endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => config('api-sync.default_negocio', 'OSSAB'),
            'TipIndex' => 0, // Example: might need different value
        ];
    }
}
