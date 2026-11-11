<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Activity;
use Illuminate\Http\Request;

/** @mixin Activity */
class ActivityResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $prospect = null;
        if ($this->relationLoaded('prospect') && $this->prospect) {
            $prospect = $this->prospect;
        } elseif ($this->relationLoaded('followUp') && $this->followUp && $this->followUp->relationLoaded('prospect') && $this->followUp->prospect) {
            $prospect = $this->followUp->prospect;
        }

        return [
            'id' => $this->id,
            'prospect_id' => $this->prospect_id ?? $this->followUp?->prospect_id,
            'prospect' => $prospect ? new ProspectSummaryResource($prospect) : null,
            'is_linked' => $this->isLinked(),
            'is_linked_to_follow_up' => $this->isLinkedToFollowUp(),
            // direct = created on its own, follow_up = converted/linked from a follow-up.
            'origin' => $this->origin(),
            'follow_up_id' => $this->follow_up_task_id,
            'follow_up' => $this->when(
                $this->relationLoaded('followUp') && $this->followUp,
                fn () => new FollowUpTaskResource($this->followUp)
            ),
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'outcome' => $this->outcome,
            'activity_at' => $this->iso($this->activity_at),
            'linked_at' => $this->iso($this->linked_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
