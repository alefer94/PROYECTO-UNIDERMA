<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagSubcategory extends Model
{
    protected $table = 'tag_subcategories';
    protected $primaryKey = 'IdSubClasificador'; // API: IdSubClasificador, BD: N_I_FAMILIA
    public $incrementing = true;
    protected $keyType = 'int';
    
    protected $fillable = [
        'IdSubClasificador', // API: IdSubClasificador
        'IdClasificador',    // API: IdClasificador
        'Nombre',            // API: Nombre
        'Corta',             // API: Corta
        'FlgActivo',         // API: FlgActivo
        'Orden',             // API: Orden
        'WooCommerceCategoryId',
    ];
    
    protected $casts = [
        'FlgActivo' => 'boolean',
        'Orden' => 'integer',
        'WooCommerceCategoryId' => 'integer',
    ];
    
    /**
     * Get the category this subcategory belongs to
     */
    public function category()
    {
        return $this->belongsTo(TagCategory::class, 'IdClasificador', 'IdClasificador');
    }
    
    /**
     * Get all tags in this subcategory
     */
    public function tags()
    {
        return $this->hasMany(Tag::class, 'IdSubClasificador', 'IdSubClasificador');
    }
}
