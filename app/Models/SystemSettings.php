<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSettings extends Model
{
    protected $table = 'system_settings';
    protected $primaryKey = 'system_settings_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'system_settings_id',
        'key',
        'value',
        'description',
    ];

    protected $casts = [
        'system_settings_id' => 'string'
    ];
}