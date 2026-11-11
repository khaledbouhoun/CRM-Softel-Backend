<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Communication history entry. There is intentionally no update or delete
 * endpoint: activities are an append-only log.
 */
class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'description',
        'outcome',
        'activity_at',
        'prospect_id',
        'follow_up_task_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'activity_at' => LocalDateTime::class,
            'linked_at' => LocalDateTime::class,
        ];
    }

    /** Null for native activities that were not linked to a prospect yet. */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    /** Set when the activity is linked to a follow-up. */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUpTask::class, 'follow_up_task_id');
    }

    public function isLinked(): bool
    {
        return $this->follow_up_task_id !== null || $this->prospect_id !== null;
    }

    public function isLinkedToFollowUp(): bool
    {
        return $this->follow_up_task_id !== null;
    }

    /** follow_up = linked to a follow-up, direct = created on its own. */
    public function origin(): string
    {
        return $this->follow_up_task_id !== null ? 'follow_up' : 'direct';
    }
}
