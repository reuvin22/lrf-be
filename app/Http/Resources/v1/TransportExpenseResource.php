<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'attendance_id' => $this->attendance_id,
            'amount'        => $this->amount,
            'route'         => $this->route,
            'site_id'       => $this->site_id,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at
        ];
    }
}
