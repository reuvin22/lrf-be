<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteAssignments extends Model
{
    protected $table = 'site_assignments';
    protected $primaryKey = 'assignment_id';
    public $timestamps = true;

    protected $fillable = [
        'assignment_id',
        'employee_id',
        'site_id',
        'is_leader',
        'start_date',
        'end_date'
    ];


    protected $casts = [
        'assignment_id' => 'string',
        'site_id' => 'string',
        'employee_id' => 'string',
        'is_leader' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employees::class, 'employee_id', 'employee_id');
    }

    public function site()
    {
        return $this->belongsTo(ConstructionSites::class, 'site_id', 'site_id');
    }

    public function subcontractor_workers()
    {
        return $this->hasMany(SubContractorsWorkers::class, 'site_id', 'site_id');
    }
}
