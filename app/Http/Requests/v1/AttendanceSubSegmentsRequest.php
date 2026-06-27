<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceSubSegmentsRequest extends FormRequest
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
            'attendance_id' => 'required|string',
            'segment_id' => 'nullable|string',
            'company_id' => 'required|string|max:50',
            'company_name' => 'required|string|max:255',
            'employee_id' => 'required|string',
            'worker_id' => 'required|string',
            'worker_name' => 'nullable|string|max:255',
            'site_id' => 'required|string',
            'site_name' => 'required|string|max:255',
            'contract_type' => 'required|string|max:100',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time'
        ];
    }
}
