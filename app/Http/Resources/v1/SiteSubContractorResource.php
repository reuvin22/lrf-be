<?php

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteSubContractorResource extends JsonResource
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
            'contract_type' => $this->contract_type,
            'subcontractor_id' => $this->subcontractor_id,
            'site' => ConstructionSiteResource::collection(
                $this->whenLoaded('site')
            ),
            'subcontractor' => SubContractorResource::collection(
                $this->whenLoaded('subcontractor')
            ),
        ];
    }
}
