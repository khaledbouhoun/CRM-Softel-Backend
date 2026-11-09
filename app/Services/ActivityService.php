<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Illuminate\Support\Facades\DB;

/**
 * Activities are an append-only communication log. The only change allowed
 * after creation is linking an unlinked activity to a prospect (or moving it,
 * after explicit confirmation); the content and dates are never modified.
 */
class ActivityService
{
    public function create(Prospect $prospect, array $data): Activity
    {
        $activity = $this->build($data);
        $activity->prospect()->associate($prospect);
        $activity->linked_at = now();
        $activity->save();

        return $this->fresh($activity);
    }

    /** Activity created on its own; the prospect is optional. */
    public function createNative(array $data): Activity
    {
        $activity = $this->build($data);

        if (! empty($data['prospect_id'])) {
            $activity->prospect()->associate(Prospect::query()->findOrFail($data['prospect_id']));
            $activity->linked_at = now();
        }

        $activity->save();

        return $this->fresh($activity);
    }

    /**
     * Records the activity a follow-up turns into. Must run inside the
     * caller's transaction: follow_up_task_id is UNIQUE, so a follow-up can
     * never yield two activities even if two requests race.
     *
     * @param  array{activity_type?: ?string, activity_description?: ?string, activity_outcome?: ?string, activity_at?: ?string}  $data
     */
    public function createFromFollowUp(FollowUpTask $task, array $data = []): Activity
    {
        $activity = new Activity([
            'type' => $task->activity_type?->value ?? $data['activity_type'] ?? ActivityType::Other->value,
            'title' => $task->title,
            'description' => $data['activity_description'] ?? $task->description ?? $task->title,
            'outcome' => $data['activity_outcome'] ?? $task->closing_note,
            'activity_at' => $data['activity_at'] ?? now(),
        ]);
        $activity->prospect_id = $task->prospect_id;
        $activity->follow_up_task_id = $task->id;
        $activity->linked_at = now();
        $activity->save();

        return $activity;
    }

    /**
     * Links an existing activity to a prospect without creating a new row.
     * Idempotent for the same prospect. Moving to a different prospect needs
     * $confirmRelink. Activities converted from a follow-up stay with the
     * follow-up's prospect.
     */
    public function link(Activity $activity, Prospect $prospect, bool $confirmRelink = false): Activity
    {
        return DB::transaction(function () use ($activity, $prospect, $confirmRelink) {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);

            if ($locked->prospect_id === $prospect->id) {
                return $this->fresh($locked);
            }

            if ($locked->follow_up_task_id !== null) {
                throw BusinessRuleException::activityLinkLocked(
                    'This activity was created from a follow-up and stays with that follow-up\'s prospect.'
                );
            }

            if ($locked->prospect_id !== null && ! $confirmRelink) {
                throw BusinessRuleException::activityAlreadyLinked(
                    'This activity is already linked to another prospect. Send confirm_relink=true to move it.'
                );
            }

            $locked->prospect()->associate($prospect);
            $locked->linked_at = now();
            $locked->save();

            return $this->fresh($locked);
        });
    }

    private function build(array $data): Activity
    {
        return new Activity([
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            // Column is NOT NULL: a native activity may only have a title.
            'description' => $data['description'] ?? '',
            'outcome' => $data['outcome'] ?? null,
            'activity_at' => $data['activity_at'] ?? now(),
        ]);
    }

    private function fresh(Activity $activity): Activity
    {
        return $activity->refresh()->load(['prospect.salesStage', 'followUp']);
    }
}
