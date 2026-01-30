<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $table = 'zones';

    protected $primaryKey = 'codZonal';

    public $incrementing = false; // porque es string

    protected $keyType = 'string';

    protected $fillable = [
        'codZonal',
        'nombre',
        'corta',
        'montoL',
        'montoR',
        'flgActivo',
        'items',
    ];

    protected $casts = [
        'items' => 'array',
    ];

    /**
     * Relación con zone_assignments
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ZoneAssignment::class, 'codZonal', 'codZonal');
    }

    /**
     * Sincroniza los items desde el array JSON a la tabla zone_assignments
     */
    public function syncItemsFromArray(array $items, bool $removeMissing = false): void
    {
        $existingAssignments = $this->assignments()->get()->keyBy('item');

        $incomingItems = collect($items)->keyBy('item');

        // 1️⃣ Actualizar o crear
        foreach ($incomingItems as $itemKey => $itemData) {
            if ($existingAssignments->has($itemKey)) {
                // Existe → actualizar si cambió
                $assignment = $existingAssignments[$itemKey];
                $changed = false;

                foreach (['tipo', 'region_code', 'province_code', 'district_code'] as $field) {
                    $newValue = $itemData[$field === 'region_code' ? 'depa' : ($field === 'province_code' ? 'prov' : ($field === 'district_code' ? 'dist' : $field))] ?? $assignment->$field;
                    if ($newValue != $assignment->$field) {
                        $assignment->$field = $newValue;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $assignment->save();
                }

            } else {
                // No existe → crear
                $this->assignments()->create([
                    'item' => $itemKey,
                    'tipo' => $itemData['tipo'] ?? 3,
                    'region_code' => $itemData['depa'] ?? null,
                    'province_code' => $itemData['prov'] ?? null,
                    'district_code' => $itemData['dist'] ?? null,
                ]);
            }
        }

        // 2️⃣ Eliminar los que ya no existen (opcional)
        if ($removeMissing) {
            $itemsToKeep = $incomingItems->keys()->toArray();
            $this->assignments()->whereNotIn('item', $itemsToKeep)->delete();
        }
    }
}
