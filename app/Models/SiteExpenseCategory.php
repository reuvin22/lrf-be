<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteExpenseCategory extends Model
{
    protected $table = 'site_expense_categories';

    protected $primaryKey = 'category_id';
    public $incrementing = false;
    public $timestamps = true;
    protected $keyType = 'string';
    
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
}
