<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConstructionSiteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'site_id' => $this->site_id,
            'site_code' => $this->site_code,
            'site_name' => $this->site_name,
            'client_name' => $this->client_name,
            'contract_type' => $this->contract_type,
            'address' => $this->address,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'contract_amount' => $this->contract_amount,
            'dotto_genka_code' => $this->dotto_genka_code,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // 'site_assignments' => SiteAssignmentResource::collection(
            //     $this->whenLoaded('siteAssignments')
            // ),

            'subcontractors' => SubContractorResource::collection(
                $this->whenLoaded('subcontractors')
            ),

            // 'expenses' => SiteExpenseCategoryResource::collection(
            //     $this->whenLoaded('expenses')
            // ),
        ];
    }
}
