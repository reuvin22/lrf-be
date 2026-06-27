<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSubcontractorSegmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_id' => $this->attendance_id,
            'employee_id' => $this->employee_id,
            'segment' => new SegmentResource($this->whenLoaded('segment')),
            'worker' => new SubContractorWorkerResource($this->whenLoaded('worker')),
            'site' => new ConstructionSiteResource($this->whenLoaded('site')),
            'subcontractor' => new SubContractorResource($this->whenLoaded('subcontractor')),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'contract_type' => $this->contract_type,
        ];
    }
}
