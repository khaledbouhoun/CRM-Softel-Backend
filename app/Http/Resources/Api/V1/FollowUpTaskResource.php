<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FollowUpTask;
use Illuminate\Http\Request;

/** @mixin FollowUpTask */
class FollowUpTaskResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prospect_id' => $this->prospect_id,
            'prospect' => new ProspectSummaryResource($this->whenLoaded('prospect')),
            'title' => $this->title,
            'description' => $this->description,
            'activity_type' => $this->activity_type?->value,
            // Id of the activity this follow-up was converted into, if any.
            'activity_id' => $this->whenLoaded('activity', fn () => $this->activity?->id),
            'scheduled_at' => $this->iso($this->scheduled_at),
            'status' => $this->status->value,
            'due_state' => $this->dueState(),
            'completed_at' => $this->iso($this->completed_at),
            'cancelled_at' => $this->iso($this->cancelled_at),
            'closing_note' => $this->closing_note,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
