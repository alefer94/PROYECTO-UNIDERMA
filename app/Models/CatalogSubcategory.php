<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogSubcategory extends Model
{
    protected $table = 'catalog_subcategories';
    protected $primaryKey = 'CodSubClasificador'; // API: CodSubClasificador, BD: C_C_FAMILIA
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'CodSubClasificador', // API: CodSubClasificador
        'CodTipcat',          // API: CodTipcat
        'CodClasificador',    // API: CodClasificador
        'Nombre',             // API: Nombre
        'WooCommerceCategoryId',
    ];
    
    protected $casts = [
        'WooCommerceCategoryId' => 'integer',
    ];
    
    /**
     * Get the type this subcategory belongs to
     */
    public function type()
    {
        return $this->belongsTo(CatalogType::class, 'CodTipcat', 'Tipcat');
    }
    
    /**
     * Get the category this subcategory belongs to
     */
    public function category()
    {
        return $this->belongsTo(CatalogCategory::class, 'CodClasificador', 'CodClasificador');
    }
    
    /**
     * Get all products in this subcategory
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'CodSubclasificador', 'CodSubClasificador');
    }
}
