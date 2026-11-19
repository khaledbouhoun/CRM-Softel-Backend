<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;

/** @mixin Product */
class ProductResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'pack_id' => $this->pack_id,
            'pack' => $this->whenLoaded('pack', fn () => $this->pack ? ['id' => $this->pack->id, 'name' => $this->pack->name, 'code' => $this->pack->code] : null),
            'slug' => $this->slug,
            'image_path' => $this->image_path,
            'gallery' => $this->gallery ?? [],
            'full_description' => $this->full_description,
            'features' => $this->features ?? [],
            'specifications' => $this->specifications ?? [],
            'is_active_on_web' => $this->is_active_on_web,
            'demo_url' => $this->demo_url,
            'prospects_count' => $this->whenCounted('prospects'),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
