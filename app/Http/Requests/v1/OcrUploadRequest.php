<?php

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

class OcrUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // allow all requests
    }

    public function rules(): array
    {
        return [
            'uploaded_by' => 'nullable|uuid',
            'category_id' => 'nullable|uuid',
            'site_id' => 'nullable|string',
            'site_name' => 'nullable|string',
            'subcontractor_id' => 'nullable|string',
            'subcontractor_name' => 'nullable|string',
            'attendance_id' => 'nullable|uuid',
            'upload_source' => 'nullable|string',
            'status' => 'nullable|string',
            'image_path' => 'nullable|string',
            'ocr_result_amount' => 'nullable|integer',
            'ocr_result_date' => 'nullable|date',
            'ocr_result_raw' => 'nullable|string',
            'image_base64' => 'nullable|string',
            'images_base64' => 'nullable|array',
            'images_base64.*' => 'nullable|string',
            'previous_image_paths' => 'nullable|array',
            'previous_image_paths.*' => 'nullable|string',
            'use_vision' => 'nullable|boolean',
            'confirmed' => 'nullable|boolean',
            'confirmed_by' => 'nullable|uuid',
            'confirmed_at' => 'nullable|date',

            'note' => 'nullable|string',

            'uploaded_at' => 'nullable|date',
            'processed_at' => 'nullable|date',
        ];
    }
}