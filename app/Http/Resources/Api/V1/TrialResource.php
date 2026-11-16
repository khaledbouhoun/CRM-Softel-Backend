<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Trial;
use Illuminate\Http\Request;

/** @mixin Trial */
class TrialResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prospect_id' => $this->prospect_id,
            'product' => ['id' => $this->product->id, 'name' => $this->product->name],
            'state' => $this->state(),
            'url' => $this->url,
            'starts_at' => $this->iso($this->starts_at),
            'ends_at' => $this->iso($this->ends_at),
            'revoked_at' => $this->iso($this->revoked_at),
            'created_by' => $this->created_by,
            'internal_notes' => $this->internal_notes,
        ];
    }
}
