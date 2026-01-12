<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'catalogs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'codCatalogo',
        'codTipcat',
        'codClasificador',
        'codSubclasificador',
        'nombre',
        'corta',
        'descripcion',
        'codLaboratorio',
        'registro',
        'presentacion',
        'composicion',
        'bemeficios',
        'modoUso',
        'contraindicaciones',
        'advertencias',
        'precauciones',
        'tipReceta',
        'showModo',
        'precio',
        'stock',
        'home',
        'link',
        'pasCodTag',
        'flgActivo',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'tipReceta' => 'integer',
        'showModo' => 'integer',
        'precio' => 'decimal:2',
        'stock' => 'integer',
        'flgActivo' => 'integer',
    ];
}
