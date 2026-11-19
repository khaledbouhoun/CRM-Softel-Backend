<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Intervention extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => LocalDateTime::class,
            'scheduled_at' => LocalDateTime::class,
            'closed_at' => LocalDateTime::class,
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(InterventionType::class, 'intervention_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(InterventionStatus::class, 'intervention_status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(InterventionPriority::class, 'intervention_priority_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Affected customers of employee-originated work. */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Prospect::class, 'intervention_customers');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InterventionMessage::class)->orderBy('id');
    }

    public function isClosed(): bool
    {
        return (bool) $this->status?->is_closed;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InterventionAttachment::class);
    }
}
