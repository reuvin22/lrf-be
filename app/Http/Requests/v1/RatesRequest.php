<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RatesRequest extends FormRequest
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
            'rate_type'      => 'required|string|in:EMPLOYEE_COST,SUBCONTRACTOR_CONTRACT,SUBCONTRACTOR_WORKER_CONTRACT',
            'target_type'    => 'required|string|in:EMPLOYEE,SUBCONTRACTOR,SUBCONTRACTOR_WORKER',
            // target_id / site_id are auto-filled from the chosen name in the
            // sheet, so they're optional on the API; target_name / site_name
            // carry the human-readable selection.
            'target_id'      => 'nullable|uuid',
            'target_name'    => 'nullable|string',
            'site_id'        => 'nullable|uuid',
            'site_name'      => 'nullable|string',
            'unit_price'     => 'required|numeric|min:0',
            'effective_from' => 'required|date',
            'effective_to'   => 'nullable|date|after_or_equal:effective_from',
        ];
    }
}
