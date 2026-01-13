<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';
    protected $primaryKey = 'CodCatalogo'; // API: CodCatalogo
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'CodCatalogo',        // API: CodCatalogo
        'CodTipcat',          // API: CodTipcat
        'CodClasificador',    // API: CodClasificador
        'CodSubclasificador', // API: CodSubclasificador
        'CodLaboratorio',     // API: CodLaboratorio
        'Nombre',             // API: Nombre
        'Corta',              // API: Corta
        'Descripcion',        // API: Descripcion
        'Registro',           // API: Registro
        'Presentacion',       // API: Presentacion
        'Composicion',        // API: Composicion
        'Bemeficios',         // API: Bemeficios
        'ModoUso',            // API: ModoUso
        'Contraindicaciones', // API: Contraindicaciones
        'Advertencias',       // API: Advertencias
        'Precauciones',       // API: Precauciones
        'TipReceta',          // API: TipReceta
        'ShowModo',           // API: ShowModo
        'Precio',             // API: Precio
        'Stock',              // API: Stock
        'Home',               // API: Home
        'Link',               // API: Link
        'PasCodTag',          // API: PasCodTag
        'FlgActivo',          // API: FlgActivo
    ];
    
    protected $casts = [
        'TipReceta' => 'integer',
        'ShowModo' => 'integer',
        'Precio' => 'decimal:2',
        'Stock' => 'integer',
        'FlgActivo' => 'boolean',
    ];
    
    /**
     * Get the laboratory that manufactures this product
     */
    public function laboratory()
    {
        return $this->belongsTo(Laboratory::class, 'CodLaboratorio', 'CodLaboratorio');
    }
    
    /**
     * Get the catalog type of this product
     */
    public function catalogType()
    {
        return $this->belongsTo(CatalogType::class, 'CodTipcat', 'Tipcat');
    }
    
    /**
     * Get the catalog category of this product
     */
    public function catalogCategory()
    {
        return $this->belongsTo(CatalogCategory::class, 'CodClasificador', 'CodClasificador');
    }
    
    /**
     * Get the catalog subcategory of this product
     */
    public function catalogSubcategory()
    {
        return $this->belongsTo(CatalogSubcategory::class, 'CodSubclasificador', 'CodSubClasificador');
    }
    
    /**
     * Get all tags for this product (many-to-many)
     */
    public function tags()
    {
        return $this->belongsToMany(
            Tag::class,
            'product_tag',
            'CodCatalogo',
            'IdTag',
            'CodCatalogo',
            'IdTag'
        )->withPivot('FlgActivo')
         ->withTimestamps();
    }
    
    /**
     * Sync tags from PasCodTag string (IDs separated by ;)
     */
    public function syncTagsFromString(?string $pasCodTag = null)
    {
        $tagString = $pasCodTag ?? $this->PasCodTag;
        
        if (empty($tagString)) {
            $this->tags()->detach();
            return;
        }
        
        $tagIds = array_filter(explode(';', $tagString));
        $this->tags()->sync($tagIds);
    }
}
