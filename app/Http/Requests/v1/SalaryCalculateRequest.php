<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class SalaryCalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each element of `employees` is one worker. `employment_type` selects the
     * formula; the remaining fields are the period inputs for that type and are
     * all optional (the calculator treats missing values as 0 / defaults).
     */
    public function rules(): array
    {
        return [
            'employees'                   => 'required|array|min:1',
            'employees.*.employment_type' => 'required|in:FULL_TIME,PART_TIME,CONTRACT,DAILY',
            'employees.*.employee_id'     => 'nullable|string',
            'employees.*.name'            => 'nullable|string',

            // FULL_TIME
            'employees.*.base_salary'        => 'nullable|numeric|min:0',
            'employees.*.monthly_work_hours' => 'nullable|numeric|min:0',
            'employees.*.night_hours'        => 'nullable|numeric|min:0',
            'employees.*.commute_cost'       => 'nullable|numeric|min:0',

            // PART_TIME
            'employees.*.hourly_rate'     => 'nullable|numeric|min:0',
            'employees.*.worked_hours'    => 'nullable|numeric|min:0',
            'employees.*.break_deduction' => 'nullable|numeric|min:0',

            // CONTRACT
            'employees.*.monthly_contract_salary' => 'nullable|numeric|min:0',
            'employees.*.project_allowance'       => 'nullable|numeric|min:0',
            'employees.*.transportation'          => 'nullable|numeric|min:0',
            'employees.*.other_allowances'        => 'nullable|numeric|min:0',
            'employees.*.overtime_pay'            => 'nullable|numeric|min:0',

            // DAILY
            'employees.*.daily_rate'           => 'nullable|numeric|min:0',
            'employees.*.worked_days'          => 'nullable|numeric|min:0',
            'employees.*.holiday_days'         => 'nullable|numeric|min:0',
            'employees.*.overtime_hourly_rate' => 'nullable|numeric|min:0',

            // shared
            'employees.*.overtime_hours' => 'nullable|numeric|min:0',
            'employees.*.holiday_hours'  => 'nullable|numeric|min:0',
            'employees.*.deductions'     => 'nullable|numeric|min:0',

            // optional per-call multiplier overrides (otherwise pulled from settings)
            'employees.*.overtime_multiplier' => 'nullable|numeric|min:0',
            'employees.*.holiday_multiplier'  => 'nullable|numeric|min:0',
            'employees.*.night_multiplier'    => 'nullable|numeric|min:0',
        ];
    }
}
