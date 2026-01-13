<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Services\RestApiSyncService;

class ProductSyncService extends RestApiSyncService
{
    protected function getModel(): string
    {
        return Product::class;
    }
    
    protected function getEndpoint(): string
    {
        return config('api-sync.endpoints.products');
    }
    
    protected function getFieldMapping(): array
    {
        return [
            // API returns camelCase → Database uses PascalCase
            'codCatalogo' => 'CodCatalogo',
            'codTipcat' => 'CodTipcat',
            'codClasificador' => 'CodClasificador',
            'codSubclasificador' => 'CodSubclasificador',
            'codLaboratorio' => 'CodLaboratorio',
            'nombre' => 'Nombre',
            'corta' => 'Corta',
            'descripcion' => 'Descripcion',
            'registro' => 'Registro',
            'presentacion' => 'Presentacion',
            'composicion' => 'Composicion',
            'bemeficios' => 'Bemeficios',
            'modoUso' => 'ModoUso',
            'contraindicaciones' => 'Contraindicaciones',
            'advertencias' => 'Advertencias',
            'precauciones' => 'Precauciones',
            'tipReceta' => 'TipReceta',
            'showModo' => 'ShowModo',
            'precio' => 'Precio',
            'stock' => 'Stock',
            'home' => 'Home',
            'link' => 'Link',
            'pasCodTag' => 'PasCodTag',
            'flgActivo' => 'FlgActivo',
        ];
    }
    
    protected function getPrimaryKey(): string
    {
        return 'CodCatalogo';
    }
    
    /**
     * Default parameters for products endpoint
     */
    protected function getDefaultParams(): array
    {
        return [
            'Negocio' => config('api-sync.default_negocio', 'OSSAB'),
            'TipIndex' => 1,
            // Can add filters like:
            // 'FlgActivo' => 1, // Only active products
        ];
    }
    
    /**
     * Override to sync tags after products are saved
     */
    protected function syncToDatabase(array $data): array
    {
        // First, sync products
        $result = parent::syncToDatabase($data);
        
        // Then, sync tags for each product
        foreach ($data as $item) {
            if (!empty($item['PasCodTag'])) {
                $product = Product::find($item['CodCatalogo']);
                if ($product) {
                    $product->syncTagsFromString($item['PasCodTag']);
                }
            }
        }
        
        return $result;
    }
}
