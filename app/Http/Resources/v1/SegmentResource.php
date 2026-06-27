<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SegmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee' => $this->employee_id,
            'segment_id' => $this->segment_id,
            'attendance_id' => $this->attendance_id,
            'type' => $this->type,
            'segment_type' => $this->segment_type,
            'site_id' => $this->site_id,
            'site_name' => $this->site_name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
