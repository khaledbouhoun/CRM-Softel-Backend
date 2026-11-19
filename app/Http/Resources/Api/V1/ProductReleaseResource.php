<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProductRelease;
use Illuminate\Http\Request;

/** Staff view of a release. @mixin ProductRelease */
class ProductReleaseResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'version_number' => $this->version_number,
            'changelog' => $this->changelog,
            'download_url' => $this->download_url,
            'installer' => $this->hasInstaller() ? [
                'name' => $this->installer_name, 'size' => $this->installer_size,
                'download_url' => route('api.v1.product-releases.download', ['release' => $this->id], false),
            ] : null,
            'release_date' => $this->release_date?->toDateString(),
            'audience' => $this->audience,
            'customer_ids' => $this->whenLoaded('customers', fn () => $this->customers->pluck('id')->all()),
            'is_published' => $this->is_published,
            'created_by' => $this->created_by,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
