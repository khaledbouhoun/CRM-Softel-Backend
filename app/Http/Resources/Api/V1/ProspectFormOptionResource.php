<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProspectFormOption;
use Illuminate\Http\Request;

/** @mixin ProspectFormOption */
class ProspectFormOptionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'requires_details' => $this->requires_details,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
