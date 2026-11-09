<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProspectStageHistory;
use Illuminate\Http\Request;

/** @mixin ProspectStageHistory */
class ProspectStageHistoryResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prospect_id' => $this->prospect_id,
            'from_stage_id' => $this->from_stage_id,
            'from_stage' => $this->when(
                $this->relationLoaded('fromStage'),
                fn () => $this->fromStage ? new SalesStageResource($this->fromStage) : null,
            ),
            'to_stage_id' => $this->to_stage_id,
            'to_stage' => new SalesStageResource($this->whenLoaded('toStage')),
            'loss_reason_id' => $this->loss_reason_id,
            'loss_reason' => $this->when(
                $this->relationLoaded('lossReason'),
                fn () => $this->lossReason ? new LossReasonResource($this->lossReason) : null,
            ),
            'loss_note' => $this->loss_note,
            'changed_at' => $this->iso($this->changed_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
