<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterventionMessage extends Model
{
    protected $guarded = [];

    public function status(): BelongsTo
    {
        return $this->belongsTo(InterventionStatus::class, 'status_id');
    }
}
