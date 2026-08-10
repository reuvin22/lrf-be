<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceDocumentConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmed_by' => 'required|string',
            'subcontractor_id' => 'nullable|string|required_without:new_subcontractor_name',
            'new_subcontractor_name' => 'nullable|string|required_without:subcontractor_id',

            'lines' => 'required|array|min:1',
            'lines.*.line_id' => 'required|string',
            'lines.*.site_id' => 'nullable|string|required_without:lines.*.new_site_name',
            'lines.*.new_site_name' => 'nullable|string|required_without:lines.*.site_id',
            'lines.*.amount' => 'nullable|numeric',
            'lines.*.amount_with_tax' => 'nullable|numeric',
        ];
    }
}
