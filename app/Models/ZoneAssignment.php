<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZoneAssignment extends Model
{
    protected $table = 'zone_assignments';

    protected $fillable = [
        'codZonal',
        'item',
        'tipo',
        'region_code',
        'province_code',
        'district_code',
    ];

    /**
     * Relación con Zone
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'codZonal', 'codZonal');
    }

    /**
     * Relación con District (usando clave compuesta)
     * Nota: Laravel no soporta nativamente claves compuestas en relaciones,
     * por lo que usamos whereColumn para las condiciones adicionales
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_code', 'code')
            ->whereColumn('zone_assignments.region_code', 'districts.region_code')
            ->whereColumn('zone_assignments.province_code', 'districts.province_code');
    }

    /**
     * Relación con Province (usando clave compuesta)
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code')
            ->whereColumn('zone_assignments.region_code', 'provinces.region_code');
    }

    /**
     * Relación con Region
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_code', 'code');
    }
}
