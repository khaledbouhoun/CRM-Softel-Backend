<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

/** Generic output for configuration rows: every column of the row. */
class ConfigEntityResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return collect($this->resource->attributesToArray())
            ->map(fn ($v, $k) => in_array($k, ['created_at', 'updated_at'], true) ? $this->iso($v) : $v)
            ->all();
    }
}
