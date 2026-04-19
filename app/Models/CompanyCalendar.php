<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyCalendar extends Model
{
    protected $table = 'company_calendars';

    protected $primaryKey = 'calendar_id';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'calendar_id',
        'date',
        'day_type',
        'note',
    ];

    protected $casts = [
        'calendar_id' => 'string',
        'day_type' => 'integer',
        'date' => 'date',
    ];
}
