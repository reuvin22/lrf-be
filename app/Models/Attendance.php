<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Attendance extends Model
{
    protected $table = 'attendances';

    protected $primaryKey = 'attendance_id';

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'employee_id',
        'work_date',
        'status',
        'total_work_minutes',
        'overtime_minutes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'total_work_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->attendance_id) {
                $model->attendance_id = (string) Str::uuid();
            }
        });
    }

    public function segments()
    {
        return $this->hasMany(Segment::class, 'attendance_id', 'attendance_id');
    }

    public function transportation_expenses()
    {
        return $this->hasMany(TransportationExpenses::class, 'attendance_id', 'attendance_id');
    }

    public function attendance_subcontractor_segments()
    {
        return $this->hasMany(AttendanceSubSegments::class, 'attendance_id', 'attendance_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employees::class, 'employee_id', 'employee_id');
    }
}
