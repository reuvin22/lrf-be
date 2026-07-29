<?php

namespace App\Http\Requests\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubContractorWorkerRequest extends FormRequest
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
            'subcontractor_id' => 'nullable|string',
            'name' => 'required|string',
            'name_kana' => 'nullable|string',
            'status' => 'required|string|in:ACTIVE,INACTIVE'
        ];
    }
}
