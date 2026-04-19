<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayType extends Model
{
    protected $table = 'day_types';

    protected $fillable = [
        'value',
        'description',
        'overtime_multiplier',
    ];

    protected $casts = [
        'overtime_multiplier' => 'decimal:2',
    ];
}