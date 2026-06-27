<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'name_kana' => $this->name_kana,
            'line_user_id' => $this->line_user_id,
            'email' => $this->email,
            'employment_type' => $this->employment_type,
            'role' => $this->role,
            'base_salary' => $this->base_salary,
            'monthly_work_hours' => $this->monthly_work_hours,
            'cost_rate' => $this->cost_rate,
            'commute_cost_monthly' => $this->commute_cost_monthly,
            'joined_date' => $this->joined_date,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'working_days' => new AttendanceResource($this->whenLoaded('attendances'))
        ];
    }
}
