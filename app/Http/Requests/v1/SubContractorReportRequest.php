<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class SubContractorReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isStore = $this->isMethod('POST');

        return [
            'attendance_id' => $isStore ? 'required|uuid' : 'sometimes|uuid',
            'employee_id'   => $isStore ? 'required|uuid' : 'sometimes|uuid',
            'worker_id'     => 'nullable|uuid',
            'worker_name'   => $isStore ? 'required|string|max:255' : 'sometimes|string|max:255',
            'contract_type' => $isStore ? 'required|string|max:255' : 'sometimes|string|max:255',
            'company_name'  => $isStore ? 'required|string|max:255' : 'sometimes|string|max:255',
            'site_id'       => $isStore ? 'required|uuid' : 'sometimes|uuid',
            'start_time'    => $isStore ? 'required|date' : 'sometimes|date',
            'end_time'      => $isStore ? 'required|date|after_or_equal:start_time' : 'nullable|date',
        ];
    }
}
