<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesStage;
use Illuminate\Http\Request;

/** @mixin SalesStage */
class SalesStageResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'stage_type' => $this->stage_type->value,
            'is_active' => $this->is_active,
            'prospects_count' => $this->whenCounted('prospects'),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
