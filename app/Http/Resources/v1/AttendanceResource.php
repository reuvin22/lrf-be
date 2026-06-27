<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->attendance_id,
            'work_date' => $this->work_date,
            'status' => $this->status,
            'total_work_minutes' => $this->total_work_minutes,
            'overtime_minutes' => $this->overtime_minutes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'employees' => EmployeeResource::collection(
                $this->whenLoaded('employees')
            ),

            'activities' => SegmentResource::collection(
                $this->whenLoaded('segments')
            ),

            'transportation_expenses' => TransportExpenseResource::collection(
                $this->whenLoaded('transportation_expenses')
            ),

            'attendance_subcontractor' => AttendanceSubcontractorSegmentResource::collection(
                $this->whenLoaded('attendance_subcontractor_segments')
            ),
        ];
    }
}
