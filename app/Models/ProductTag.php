<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductTag extends Pivot
{
    protected $table = 'product_tag';
    
    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'CodCatalogo', // API: CodCatalogo
        'IdTag',       // API: IdTag
        'FlgActivo',   // API: FlgActivo
    ];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'FlgActivo' => 'boolean',
    ];
    
    /**
     * Get the product that owns the pivot.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'CodCatalogo', 'CodCatalogo');
    }
    
    /**
     * Get the tag that owns the pivot.
     */
    public function tag()
    {
        return $this->belongsTo(Tag::class, 'IdTag', 'IdTag');
    }
}
