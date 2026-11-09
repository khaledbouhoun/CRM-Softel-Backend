<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Prospect;
use Illuminate\Http\Request;

/** @mixin Prospect */
class ProspectResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'business_activity' => $this->business_activity,
            'source' => $this->source,
            'source_other' => $this->source_other,
            'previous_software' => $this->previous_software,
            'notes' => $this->notes,
            'sales_stage_id' => $this->sales_stage_id,
            'sales_stage' => new SalesStageResource($this->whenLoaded('salesStage')),
            'loss_reason_id' => $this->loss_reason_id,
            'loss_reason' => $this->when(
                $this->relationLoaded('lossReason'),
                fn () => $this->lossReason ? new LossReasonResource($this->lossReason) : null,
            ),
            'loss_note' => $this->loss_note,
            'products' => ProductResource::collection($this->whenLoaded('products')),
            'activities_count' => $this->whenCounted('activities'),
            'pending_follow_ups_count' => $this->whenCounted('pendingFollowUps'),
            'last_activity_at' => $this->whenHas('activities_max_activity_at', fn ($v) => $this->iso($v)),
            'next_follow_up_at' => $this->whenHas('pending_follow_ups_min_scheduled_at', fn ($v) => $this->iso($v)),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
