<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['code', 'name', 'region_code'];

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_code', 'code');
    }

    public function districts()
    {
        return $this->hasMany(District::class, 'province_code', 'code');
    }
}
