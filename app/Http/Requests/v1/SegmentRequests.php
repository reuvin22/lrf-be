<?php

namespace App\Http\Requests\v1;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SegmentRequests extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->start_time) {
            $this->merge([
                'start_time' => Carbon::parse($this->start_time)->utc(),
            ]);
        }

        if ($this->end_time) {
            $this->merge([
                'end_time' => Carbon::parse($this->end_time)->utc(),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attendance_id' => 'required|uuid',
            'employee_id' => 'required|uuid',
            'site_id' => 'nullable|uuid',
            'segment_type' => 'required|string|in:TRAVEL,SITE,OFFICE',
            'site_id' => 'nullable|uuid',
            'site_name' => 'nullable|string',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after_or_equal:start_time',
            'type' => 'required|string'
        ];
    }

    public function messages(): array
    {
        return [
            'segment_type.in' => 'Segment type must be TRAVEL, SITE, or OFFICE.',
            'end_time.after_or_equal' => 'End time must be after or equal to start time.',
        ];
    }
}
