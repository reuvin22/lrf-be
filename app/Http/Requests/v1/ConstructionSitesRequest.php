<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class ConstructionSitesRequest extends FormRequest
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
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'site_code' => 'nullable|string|max:255',
            'site_name' => 'nullable|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'contract_type' => 'required|string|in:QUASI_DELEGATION,FIXED_PRICE',
            'address' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:PREPARING,IN_PROGRESS,COMPLETED',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'contract_amount' => 'nullable|integer|min:0',
            'dotto_genka_code' => 'nullable|string|max:255',
        ];
    }

    /**
     * Optional custom error messages
     */
    public function messages(): array
    {
        return [
            'site_name.required' => 'Site name is required.',
            'contract_type.required' => 'Contract type is required.',
            'status.required' => 'Status is required.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
        ];
    }
}
