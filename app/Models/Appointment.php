<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled event. It is NOT an Activity: only after the event happened does
 * AppointmentService::complete() record an Activity and link it here.
 */
class Appointment extends Model
{
    use HasFactory;

    public const HELD = 'held';

    public const PROPOSED = 'proposed';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    /** Statuses that occupy the calendar. */
    public const BLOCKING = [self::HELD, self::PROPOSED, self::CONFIRMED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => LocalDateTime::class,
            'ends_at' => LocalDateTime::class,
            'hold_expires_at' => LocalDateTime::class,
            'decided_at' => LocalDateTime::class,
            'send_whatsapp' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class, 'appointment_type_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(AppointmentSeries::class, 'series_id');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING);
    }

    /** Held/proposed appointments past their expiry no longer block anything. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->blocking()->where(function (Builder $q) {
            $q->where('status', self::CONFIRMED)->orWhere('hold_expires_at', '>', now()->format('Y-m-d H:i:s'));
        });
    }

    public function scopeOverlapping(Builder $query, mixed $start, mixed $end): Builder
    {
        return $query->where('starts_at', '<', LocalDateTime::toLocal($end)->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', LocalDateTime::toLocal($start)->format('Y-m-d H:i:s'));
    }

    public function isTemporary(): bool
    {
        return in_array($this->status, [self::HELD, self::PROPOSED], true);
    }

    public function isHoldExpired(): bool
    {
        return $this->isTemporary() && $this->hold_expires_at !== null && $this->hold_expires_at->lte(now());
    }
}
