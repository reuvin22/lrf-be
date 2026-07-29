<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrUploads extends Model
{
    protected $table = 'ocr_uploads';

    protected $primaryKey = 'upload_id';
    public $incrementing = false;
    protected $keyType = 'string';  

    protected $fillable = [
        'upload_id',
        'uploaded_by',
        'upload_source',
        'category_id',
        'attendance_id',
        'site_id',
        'subcontractor_id',
        'image_path',
        'status',
        'ocr_result_amount',
        'ocr_result_date',
        'ocr_result_raw',
        'confirmed',
        'confirmed_by',
        'confirmed_at',
        'note',
        'uploaded_at',
        'processed_at'
    ];

    protected $casts = [
        'confirmed' => 'boolean',
        'ocr_result_date' => 'date',
        'confirmed_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'processed_at' => 'datetime',
        'ocr_result_raw' => 'array',
        'upload_id' => 'string'
    ];

    public function uploader()
    {
        return $this->belongsTo(Employees::class, 'uploaded_by', 'employee_id');
    }

    public function confirmer()
    {
        return $this->belongsTo(Employees::class, 'confirmed_by', 'employee_id');
    }

    public function category()
    {
        return $this->belongsTo(OcrUploadCategory::class, 'category_id', 'category_id');
    }

    public function sites()
    {
        return $this->belongsTo(ConstructionSites::class, 'site_id', 'site_id');
    }

    public function subcontractor()
    {
        return $this->belongsTo(SubContractors::class, 'subcontractor_id', 'subcontractor_id');
    }
}
