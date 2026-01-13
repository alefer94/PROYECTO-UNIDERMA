<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'tags';
    protected $primaryKey = 'IdTag'; // API: IdTag, BD: N_I_NEMONICO
    public $incrementing = true;
    protected $keyType = 'int';
    
    protected $fillable = [
        'IdTag',             // API: IdTag
        'IdClasificador',    // API: IdClasificador
        'IdSubClasificador', // API: IdSubClasificador
        'Nombre',            // API: Nombre
        'Corta',             // API: Corta
        'FlgActivo',         // API: FlgActivo
        'Orden',             // API: Orden
    ];
    
    protected $casts = [
        'FlgActivo' => 'boolean',
        'Orden' => 'integer',
    ];
    
    /**
     * Get the category this tag belongs to
     */
    public function category()
    {
        return $this->belongsTo(TagCategory::class, 'IdClasificador', 'IdClasificador');
    }
    
    /**
     * Get the subcategory this tag belongs to
     */
    public function subcategory()
    {
        return $this->belongsTo(TagSubcategory::class, 'IdSubClasificador', 'IdSubClasificador');
    }
    
    /**
     * Get all products with this tag
     */
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'product_tag',
            'IdTag',
            'CodCatalogo',
            'IdTag',
            'CodCatalogo'
        )->withPivot('FlgActivo')
         ->withTimestamps();
    }
}
