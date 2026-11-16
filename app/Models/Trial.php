<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trial extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => LocalDateTime::class, 'ends_at' => LocalDateTime::class, 'revoked_at' => LocalDateTime::class];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** scheduled | active | expired | revoked */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->ends_at->lte(now()) => 'expired',
            $this->starts_at->gt(now()) => 'scheduled',
            default => 'active',
        };
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now()->format('Y-m-d H:i:s');

        return $query->whereNull('revoked_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now);
    }
}
