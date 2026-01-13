<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagCategory extends Model
{
    protected $table = 'tag_categories';
    protected $primaryKey = 'IdClasificador'; // API: IdClasificador, BD: N_I_GENERICO
    public $incrementing = true;
    protected $keyType = 'int';
    
    protected $fillable = [
        'IdClasificador', // API: IdClasificador
        'Nombre',         // API: Nombre
        'Corta',          // API: Corta
        'FlgActivo',      // API: FlgActivo
        'Orden',          // API: Orden
    ];
    
    protected $casts = [
        'FlgActivo' => 'boolean',
        'Orden' => 'integer',
    ];
    
    /**
     * Get all subcategories of this tag category
     */
    public function subcategories()
    {
        return $this->hasMany(TagSubcategory::class, 'IdClasificador', 'IdClasificador');
    }
    
    /**
     * Get all tags of this category
     */
    public function tags()
    {
        return $this->hasMany(Tag::class, 'IdClasificador', 'IdClasificador');
    }
}
