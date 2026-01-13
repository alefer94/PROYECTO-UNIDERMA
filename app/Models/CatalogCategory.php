<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogCategory extends Model
{
    protected $table = 'catalog_categories';
    protected $primaryKey = 'CodClasificador'; // API: CodClasificador, BD: C_C_GENERICO
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'CodClasificador', // API: CodClasificador
        'CodTipcat',       // API: CodTipcat
        'Nombre',          // API: Nombre
    ];
    
    /**
     * Get the type this category belongs to
     */
    public function type()
    {
        return $this->belongsTo(CatalogType::class, 'CodTipcat', 'Tipcat');
    }
    
    /**
     * Get all subcategories of this category
     */
    public function subcategories()
    {
        return $this->hasMany(CatalogSubcategory::class, 'CodClasificador', 'CodClasificador');
    }
    
    /**
     * Get all products in this category
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'CodClasificador', 'CodClasificador');
    }
}
