<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Set to true if the user is allowed to assign employees
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'attendance_id' => 'required|uuid',
            'employee_id' => 'required|uuid'
        ];
    }

    /**
     * Optional: custom error messages
     */
    public function messages(): array
    {
        return [
        ];
    }
}
