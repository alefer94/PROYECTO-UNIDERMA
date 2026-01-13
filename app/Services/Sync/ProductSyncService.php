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
            'nombre' => 'Nombre',
            'corta' => 'Corta',
            'descripcion' => 'Descripcion',
            'codLaboratorio' => 'CodLaboratorio',
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
            'Negocio' => '002',
            'TipIndex' => 1,
            'CodTipcat' => '%',
            'CodClasificador' => '%',
            'CodSubclasificador' => '%',
            'CodCatalogo' => '%',
            // Can add filters like:
            // 'FlgActivo' => 1, // Only active products
        ];
    }
    
    
    /**
     * Transform individual record - clean and validate data
     */
    protected function transformRecord(array $record, array $original): array
    {
        // 1. Convert empty strings and "_" to null
        $fieldsToClean = [
            'CodTipcat', 'CodClasificador', 'CodSubclasificador', 'CodLaboratorio',
            'Descripcion', 'Registro', 'Presentacion', 'Composicion', 'Bemeficios',
            'ModoUso', 'Contraindicaciones', 'Advertencias', 'Precauciones',
            'Home', 'Link', 'PasCodTag'
        ];
        
        foreach ($fieldsToClean as $field) {
            if (isset($record[$field]) && in_array($record[$field], ['', '_', '?'], true)) {
                $record[$field] = null;
            }
        }
        
        // 2. Validate foreign keys - set to null if they don't exist
        $this->validateForeignKey($record, 'CodLaboratorio', \App\Models\Laboratory::class);
        $this->validateForeignKey($record, 'CodTipcat', \App\Models\CatalogType::class, 'Tipcat');
        $this->validateForeignKey($record, 'CodClasificador', \App\Models\CatalogCategory::class);
        $this->validateForeignKey($record, 'CodSubclasificador', \App\Models\CatalogSubcategory::class);
        
        return $record;
    }
    
    /**
     * Validate that a foreign key exists, set to null if not
     */
    private function validateForeignKey(array &$record, string $field, string $model, ?string $column = null)
    {
        if (empty($record[$field])) {
            return;
        }
        
        $column = $column ?? $field;
        $exists = $model::where($column, $record[$field])->exists();
        
        if (!$exists) {
            \Log::warning("FK validation failed for Product", [
                'field' => $field,
                'value' => $record[$field],
                'product' => $record['CodCatalogo'] ?? 'unknown',
            ]);
            $record[$field] = null;
        }
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
