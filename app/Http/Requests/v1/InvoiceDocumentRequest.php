<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uploaded_by' => 'nullable|uuid',
            'category_id' => 'nullable|string',
            'files' => 'required|array|min:1',
            'files.*.data' => 'required|string',
            'note' => 'nullable|string',
        ];
    }
}
