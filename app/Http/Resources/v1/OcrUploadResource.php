<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OcrUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'upload_id' => $this->upload_id,
            'uploaded_by' => $this->uploaded_by,
            'category' => new OcrUploadCategoriesResource($this->category),
            'site' => new ConstructionSiteResource($this->sites),
            'subcontractor_id' => $this->subcontractor_id,
            'attendance_id' => $this->attendance_id,

            'upload_source' => $this->upload_source,
            'status' => $this->status,

            'image_path' => $this->image_path,

            'ocr_result_amount' => $this->ocr_result_amount,
            'ocr_result_date' => $this->ocr_result_date,
            'ocr_result_raw' => $this->ocr_result_raw,

            'confirmed' => $this->confirmed,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at,

            'note' => $this->note,

            'uploaded_at' => $this->uploaded_at,
            'processed_at' => $this->processed_at,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
