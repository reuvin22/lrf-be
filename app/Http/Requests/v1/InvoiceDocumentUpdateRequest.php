<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_name_raw' => 'nullable|string',
            'subcontractor_id' => 'nullable|string',
            'subcontractor_name' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'billing_month' => 'nullable|string',
            'document_type' => 'nullable|string|in:INVOICE,MONTHLY_STATEMENT,QUOTATION,DELIVERY_NOTE,EMAIL,OTHER',
            'category_id' => 'nullable|string',
            'subtotal' => 'nullable|numeric',
            'tax_amount' => 'nullable|numeric',
            'total_with_tax' => 'nullable|numeric',
            'note' => 'nullable|string',

            'lines' => 'nullable|array',
            'lines.*.site_id' => 'nullable|string',
            'lines.*.site_name' => 'nullable|string',
            'lines.*.site_name_raw' => 'nullable|string',
            'lines.*.amount' => 'nullable|numeric',
            'lines.*.amount_with_tax' => 'nullable|numeric',
        ];
    }
}
