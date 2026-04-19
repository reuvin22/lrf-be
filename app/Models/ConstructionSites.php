<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConstructionSites extends Model
{
    protected $table = 'construction_sites';

    protected $primaryKey = 'site_id';
    protected $keyType = 'string';
    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'site_id',
        'site_code',
        'site_name',
        'client_name',
        'contract_type',
        'address',
        'status',
        'start_date',
        'end_date',
        'contract_amount',
        'dotto_genka_code',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'contract_amount' => 'integer',
    ];

    public function siteAssignments()
    {
        return $this->hasMany(SiteAssignments::class, 'site_id', 'site_id');
    }

    public function subcontractors()
    {
        return $this->belongsToMany(
            SubContractors::class,
            'site_subcontractors',
            'site_id',
            'subcontractor_id',
            'site_id',
            'subcontractor_id'
        )->with('workers');
    }

    public function expenses()
    {
        return $this->hasMany(SiteExpenseCategory::class, 'site_id', 'site_id');
    }

    public function siteSubcontractors()
    {
        return $this->hasMany(SiteSubContractors::class, 'site_id', 'site_id');
    }

    public function ocrUpload()
    {
        return $this->belongsTo(OcrUploads::class, 'site_id', 'site_id');
    }
}
