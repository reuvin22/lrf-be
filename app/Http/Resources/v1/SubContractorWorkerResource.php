<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubContractorWorkerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'worker_id' => $this->worker_id,
            'subcontractor_id' => $this->subcontractor_id,
            'name' => $this->name,
            'status' => $this->status,
            'name_kana' => $this->name_kana
        ];
    }
}
