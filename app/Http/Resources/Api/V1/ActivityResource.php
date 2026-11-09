<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Activity;
use Illuminate\Http\Request;

/** @mixin Activity */
class ActivityResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prospect_id' => $this->prospect_id,
            'prospect' => $this->when(
                $this->relationLoaded('prospect'),
                fn () => $this->prospect ? new ProspectSummaryResource($this->prospect) : null,
            ),
            'is_linked' => $this->isLinked(),
            // direct = created on its own, follow_up = converted from a follow-up.
            'origin' => $this->origin(),
            'follow_up_id' => $this->follow_up_task_id,
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
