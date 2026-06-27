<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSubContractors extends Model
{
    protected $table = 'site_subcontractors';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'site_id',
        'subcontractor_id',
        'contract_type',
    ];

    protected $casts = [
        'contract_type' => 'string',
    ];

    public function site()
    {
        return $this->hasMany(ConstructionSites::class, 'site_id', 'site_id');
    }

    public function subcontractor()
    {
        return $this->hasMany(SubContractors::class, 'subcontractor_id', 'subcontractor_id');
    }
}
