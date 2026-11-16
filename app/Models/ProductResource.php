<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link (PDF, video, ...). A URL is only a pointer: visibility is enforced by the API, not the URL. */
class ProductResource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_visible' => 'boolean', 'is_training_material' => 'boolean', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
