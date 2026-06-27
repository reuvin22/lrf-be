<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class TransportationExpenseRequests extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Common rules for a single transportation expense.
     */
    protected function expenseRules(): array
    {
        return [
            'attendance_id' => 'required|uuid',
            'amount'        => 'required|integer|min:0',
            'route'         => 'nullable|string|max:255',
            'site_id'       => 'required|uuid',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Supports both single expense or bulk expenses in "expenses" array.
     */
    public function rules(): array
    {
        return [
            '*.employee_id' => 'required|uuid',
            '*.attendance_id' => 'required|uuid',
            '*.amount'        => 'required|integer|min:0',
            '*.route'         => 'nullable|string|max:255',
            '*.site_id'       => 'required|uuid',
        ];
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'attendance_id.required_without' => 'Attendance ID is required if not sending bulk expenses.',
            'attendance_id.exists'           => 'The specified attendance does not exist.',
            'amount.required_without'        => 'Amount is required if not sending bulk expenses.',
            'amount.integer'                 => 'Amount must be an integer.',
            'amount.min'                     => 'Amount cannot be negative.',
            'route.string'                   => 'Route must be a string.',
            'site_id.required_without'       => 'Site ID is required if not sending bulk expenses.',
            'site_id.exists'                 => 'The specified site does not exist.',

            'expenses.array'                 => 'Expenses must be an array.',
            'expenses.*.attendance_id.required' => 'Attendance ID is required for each expense.',
            'expenses.*.attendance_id.exists'   => 'The specified attendance does not exist for one of the expenses.',
            'expenses.*.amount.required'        => 'Amount is required for each expense.',
            'expenses.*.amount.integer'         => 'Amount must be an integer for each expense.',
            'expenses.*.amount.min'             => 'Amount cannot be negative for each expense.',
            'expenses.*.route.string'           => 'Route must be a string for each expense.',
            'expenses.*.site_id.required'       => 'Site ID is required for each expense.',
            'expenses.*.site_id.exists'         => 'The specified site does not exist for one of the expenses.',
        ];
    }
}
