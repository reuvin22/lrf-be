<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubContractors extends Model
{
    protected $table = 'subcontractors';

    protected $primaryKey = 'subcontractor_id';

    public $timestamps = true;

    protected $fillable = [
        'subcontractor_id',
        'company_name',
        'contact_person',
        'contact_phone',
        'status',
    ];

    protected $casts = [
        'subcontractor_id' => 'string',
        'status' => 'string',
    ];

    // public function siteAssignments()
    // {
    //     return $this->hasMany(SiteSubContractors::class, 'subcontractor_id', 'subcontractor_id');
    // }

    public function rates()
    {
        return $this->hasMany(Rates::class, 'target_id', 'subcontractor_id')
                    ->where('target_type', 'subcontractor');
    }

    public function sites()
    {
        return $this->hasMany(ConstructionSites::class, 'site_id', 'site_id');
    }

    public function siteSubcontractor()
    {
        return $this->hasMany(SiteSubContractors::class, 'subcontractor_id', 'subcontractor_id');
    }

    public function workers()
    {
        return $this->hasMany(SubContractorsWorkers::class, 'subcontractor_id', 'subcontractor_id');
    }

    public function employees()
    {
        return $this->hasManyThrough(
            Employees::class,
            SubContractorsWorkers::class,
            'subcontractor_id',
            'employee_id',
            'subcontractor_id',
            'worker_id'
        );
    }
}
