<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalToken extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => LocalDateTime::class, 'last_used_at' => LocalDateTime::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
