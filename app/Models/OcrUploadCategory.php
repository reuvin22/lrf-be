<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrUploadCategory extends Model
{
    protected $table = 'ocr_upload_categories';

    protected $primaryKey = 'category_id';
    protected $keyType = 'string';
    public $timestamps = true;

    protected $fillable = [
        'category_id',
        'category_name',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
        'category_id' => 'string'
    ];

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    public function uploads()
    {
        return $this->belongsTo(OcrUploads::class, 'category_id', 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }
}
