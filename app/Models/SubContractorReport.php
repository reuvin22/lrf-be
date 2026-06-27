<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubContractorReport extends Model
{
    protected $table = 'sub_contractor_reports';

    protected $fillable = [
        'attendance_id',
        'employee_id',
        'worker_id',
        'worker_name',
        'contract_type',
        'company_name',
        'site_id',
        'start_time',
        'end_time',
    ];

    // Cast timestamps
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];
}
