<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceEmployee extends Model
{
    protected $table = 'attendance_employee';
    protected $primaryKey = 'uuid';
    public $timestamps = true;

    protected $fillable = [
        'uuid',
        'attendance_id',
        'employee_id',
    ];

    public function segments()
    {
        return $this->hasMany(Segment::class, 'attendance_id', 'attendance_id')
                    ->where('employee_id', $this->employee_id);
    }
}
