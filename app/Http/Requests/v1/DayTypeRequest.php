<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DayTypeRequest extends FormRequest
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
            'value' => 'required|string|in:WORKDAY,HOLIDAY,LEGAL_HOLIDAY,NATIONAL_HOLIDAY',
            'description' => 'required|string|max:255',
            'overtime_multiplier' => 'required|numeric|min:0|max:999999.99',
        ];
    }
}
