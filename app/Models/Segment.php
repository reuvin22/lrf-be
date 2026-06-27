<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Segment extends Model
{
    protected $primaryKey = 'segment_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'segment_id',
        'attendance_id',
        'employee_id',
        'site_id',
        'segment_type',
        'site_id',
        'site_name',
        'start_time',
        'end_time',
        'type'
    ];

    protected $casts = [
        'segment_id' => 'string',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class, 'attendance_id', 'attendance_id');
    }
}
