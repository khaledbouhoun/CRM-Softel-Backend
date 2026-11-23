<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's lifetime license (never expires) or maintenance & support plan (has an expiry date). */
class Subscription extends Model
{
    public const LIFETIME = 'lifetime_license';

    public const MAINTENANCE = 'maintenance';

    /** A maintenance plan ending within this many days is reported as "expiring_soon". */
    public const EXPIRING_SOON_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => LocalDateTime::class, 'expires_at' => LocalDateTime::class];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    /** Lifetime licenses are always active; maintenance is active until its expiry date has passed. */
    public function isActive(): bool
    {
        if ($this->type === self::LIFETIME) {
            return true;
        }

        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->gte(now()));
    }

    public function daysRemaining(): ?int
    {
        if ($this->type === self::LIFETIME || $this->expires_at === null) {
            return null;
        }

        return $this->isActive() ? (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay()) : 0;
    }

    /** active | expiring_soon | expired */
    public function state(): string
    {
        if (! $this->isActive()) {
            return 'expired';
        }

        $days = $this->daysRemaining();

        return $days !== null && $days < self::EXPIRING_SOON_DAYS ? 'expiring_soon' : 'active';
    }
}
