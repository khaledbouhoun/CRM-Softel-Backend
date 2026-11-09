<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Enums\FollowUpActivityType;
use App\Enums\FollowUpStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Due-state windows (pending tasks only, application timezone, whole days):
 *  - overdue:  scheduled before today
 *  - today:    scheduled any time today
 *  - upcoming: scheduled after today
 * The three windows never overlap, so dashboard counts add up.
 */
class FollowUpTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'activity_type',
        'scheduled_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'activity_type' => FollowUpActivityType::class,
            'scheduled_at' => LocalDateTime::class,
            'completed_at' => LocalDateTime::class,
            'cancelled_at' => LocalDateTime::class,
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    /** The activity this follow-up was converted into, if any. */
    public function activity(): HasOne
    {
        return $this->hasOne(Activity::class, 'follow_up_task_id');
    }

    public function isPending(): bool
    {
        return $this->status === FollowUpStatus::Pending;
    }

    /** overdue | today | upcoming, or null when the task is no longer pending. */
    public function dueState(): ?string
    {
        if (! $this->isPending()) {
            return null;
        }

        $today = now()->startOfDay();

        return match (true) {
            $this->scheduled_at->lt($today) => 'overdue',
            $this->scheduled_at->lte($today->copy()->endOfDay()) => 'today',
            default => 'upcoming',
        };
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', FollowUpStatus::Pending->value);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->where('scheduled_at', '<', now()->startOfDay());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->pending()->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    /** @param int|null $days limit to the next N days after today; null = no limit */
    public function scopeUpcoming(Builder $query, ?int $days = null): Builder
    {
        $query->pending()->where('scheduled_at', '>', now()->endOfDay());

        if ($days !== null && $days > 0) {
            $query->where('scheduled_at', '<=', now()->addDays($days)->endOfDay());
        }

        return $query;
    }

    /** Only tasks whose prospect has not been soft-deleted. */
    public function scopeForActiveProspects(Builder $query): Builder
    {
        return $query->whereHas('prospect', fn (Builder $q) => $q->whereNull('prospects.deleted_at'));
    }
}
