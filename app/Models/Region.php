<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['code', 'name'];

    public function provinces()
    {
        return $this->hasMany(Province::class, 'region_code', 'code');
    }
}
