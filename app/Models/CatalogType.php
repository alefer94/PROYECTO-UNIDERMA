<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogType extends Model
{
    protected $table = 'catalog_types';
    protected $primaryKey = 'Tipcat'; // API: Tipcat, BD: C_C_TIPCAT
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'Tipcat',        // API: Tipcat
        'Nombre',        // API: Nombre
        'IdEstructura',  // API: IdEstructura
        'WooCommerceCategoryId',
    ];
    
    protected $casts = [
        'IdEstructura' => 'integer',
        'WooCommerceCategoryId' => 'integer',
    ];
    
    /**
     * Get all categories of this type
     */
    public function categories()
    {
        return $this->hasMany(CatalogCategory::class, 'CodTipcat', 'Tipcat');
    }
    
    /**
     * Get all subcategories of this type
     */
    public function subcategories()
    {
        return $this->hasMany(CatalogSubcategory::class, 'CodTipcat', 'Tipcat');
    }
    
    /**
     * Get all products of this type
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'CodTipcat', 'Tipcat');
    }
}
