<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Prospect;
use Illuminate\Http\Request;

/**
 * Compact prospect embedded in activity / follow-up / history rows.
 *
 * @mixin Prospect
 */
class ProspectSummaryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'sales_stage_id' => $this->sales_stage_id,
            'sales_stage' => new SalesStageResource($this->whenLoaded('salesStage')),
            'is_deleted' => $this->deleted_at !== null,
        ];
    }
}
