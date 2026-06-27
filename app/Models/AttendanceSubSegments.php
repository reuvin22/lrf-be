<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSubSegments extends Model
{
    protected $table = 'attendance_subsegments';

    protected $primaryKey = 'id';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'attendance_id',
        'segment_id',
        'company_name',
        'company_id',
        'employee_id',
        'worker_id',
        'worker_name',
        'site_id',
        'contract_type',
        'start_time',
        'end_time',
        'site_name'
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class, 'attendance_id', 'attendance_id');
    }

    public function segments()
    {
        return $this->belongsTo(Segment::class, 'segment_id', 'segment_id');
    }

    public function worker()
    {
        return $this->belongsTo(SubContractorsWorkers::class, 'worker_id', 'worker_id');
    }

    public function site()
    {
        return $this->belongsTo(ConstructionSites::class, 'site_id', 'site_id');
    }

    public function subcontractor()
    {
        return $this->belongsTo(SubContractors::class, 'company_id', 'subcontractor_id');
    }
}
