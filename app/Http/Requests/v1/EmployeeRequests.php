<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeRequests extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_code' => 'required_unless:status,PENDING|nullable|string|max:50',
            'name' => 'required|string|max:255',
            'name_kana' => 'nullable|string|max:255',
            'line_user_id' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'employment_type' => 'required_unless:status,PENDING|nullable|string|in:FULL_TIME,PART_TIME,CONTRACT',
            'salary_type' => 'nullable|string|in:HOURLY_BASED,FIXED_PRICE_BASED',
            'role' => 'required_unless:status,PENDING|nullable|string|in:GENERAL,ADMIN,ACCOUNTING',
            'base_salary' => 'required_unless:status,PENDING|nullable|integer|min:0',
            'monthly_work_hours' => 'required_unless:status,PENDING|nullable|numeric|min:0',
            'cost_rate' => 'required_unless:status,PENDING|nullable|integer|min:0',
            'commute_cost_monthly' => 'nullable|integer|min:0',
            'joined_date' => 'required_unless:status,PENDING|nullable|date',
            'status' => 'required|string|in:ACTIVE,RESIGNED,PENDING',
        ];
    }
}
