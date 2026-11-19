<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterventionMessage extends Model
{
    protected $guarded = [];

    public const KIND_MESSAGE = 'message';

    public const KIND_STATUS_CHANGE = 'status_change';

    public const KIND_REOPENED = 'reopened';

    public function isEvent(): bool
    {
        return $this->kind !== self::KIND_MESSAGE;
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(InterventionStatus::class, 'from_status_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InterventionAttachment::class, 'message_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(InterventionStatus::class, 'status_id');
    }
}
