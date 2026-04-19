<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubContractorsWorkers extends Model
{
    protected $table = 'subcontractor_workers';

    protected $primaryKey = 'worker_id';

    public $timestamps = true;

    protected $fillable = [
        'subcontractor_id',
        'worker_id',
        'name',
        'name_kana',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
        'worker_id' => 'string'
    ];

    public function subcontractor()
    {
        return $this->belongsTo(SubContractors::class, 'subcontractor_id', 'subcontractor_id');
    }

    public function rates()
    {
        return $this->hasMany(Rates::class, 'target_id', 'worker_id')
                    ->where('target_type', 'subcontractor_worker');
    }

    public function siteAssignments()
    {
        return $this->hasMany(SiteSubContractors::class, 'subcontractor_id', 'subcontractor_id');
    }

    public function employees()
    {
        return $this->hasMany(Employees::class, 'employee_id', 'employee_id');
    }
}
