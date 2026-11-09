<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LossReason;
use Illuminate\Http\Request;

/** @mixin LossReason */
class LossReasonResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'requires_note' => $this->requires_note,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
