<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laboratory extends Model
{
    protected $table = 'laboratories';

    protected $primaryKey = 'CodLaboratorio'; // API: CodLaboratorio, BD: C_C_LABORATORIO

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'CodLaboratorio', // API: CodLaboratorio
        'NomLaboratorio', // API: NomLaboratorio
        'FlgNuevo',       // API: FlgNuevo (0: antiguo, 1: nuevo)
        'WooCommerceCategoryId',
    ];

    protected $casts = [
        'FlgNuevo' => 'boolean',
        'WooCommerceCategoryId' => 'integer',
    ];

    /**
     * Get all products from this laboratory
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'CodLaboratorio', 'CodLaboratorio');
    }
}
