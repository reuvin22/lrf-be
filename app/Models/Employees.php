<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employees extends Model
{
    protected $table = 'employees';

    protected $primaryKey = 'employee_id';

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;

    protected $fillable = [
        'employee_id',
        'employee_code',
        'name',
        'name_kana',
        'line_user_id',
        'email',
        'employment_type',
        'role',
        'base_salary',
        'monthly_work_hours',
        'cost_rate',
        'commute_cost_monthly',
        'joined_date',
        'status',
    ];

    protected $casts = [
        'base_salary' => 'integer',
        'monthly_work_hours' => 'decimal:2',
        'cost_rate' => 'integer',
        'commute_cost_monthly' => 'integer',
        'joined_date' => 'date',
    ];

    public function site_assignments()
    {
        return $this->hasMany(SiteAssignments::class, 'employee_id', 'employee_id');
    }

    public function ocr_uploads()
    {
        return $this->hasMany(OcrUploads::class, 'uploaded_by', 'employee_id');
    }

    public function ocr_verifications()
    {
        return $this->hasMany(OcrUploads::class, 'confirmed_by', 'employee_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id', 'employee_id');
    }

    public function segments()
    {
        return $this->hasMany(Attendance::class, 'employee_id', 'employee_id');
    }
}
