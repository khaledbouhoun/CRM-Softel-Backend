<?php

namespace App\Services;

use App\Enums\FollowUpStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle: pending -> completed | cancelled. Completed and cancelled
 * follow-ups are final and cannot be edited, completed or cancelled again.
 *
 * A completed follow-up can be converted into an activity (the follow-up keeps
 * existing: they are distinct records linked by activities.follow_up_task_id).
 */
class FollowUpService
{
    private const FIELDS = ['title', 'description', 'activity_type', 'scheduled_at'];

    public function __construct(private readonly ActivityService $activities) {}

    public function create(Prospect $prospect, array $data): FollowUpTask
    {
        $task = new FollowUpTask(Arr::only($data, self::FIELDS));
        $task->status = FollowUpStatus::Pending;
        $task->prospect()->associate($prospect);
        $task->save();

        return $this->loaded($task);
    }

    public function update(FollowUpTask $task, array $data): FollowUpTask
    {
        return $this->whilePending($task, function (FollowUpTask $locked) use ($data) {
            $locked->fill(Arr::only($data, self::FIELDS));
            $locked->save();
        }, 'edited');
    }

    /**
     * @param  array|null  $activityData  when not null the completion also creates the
     *                                    activity, atomically (see ConvertFollowUpRequest)
     */
    public function complete(FollowUpTask $task, ?string $note = null, ?array $activityData = null): FollowUpTask
    {
        return $this->whilePending($task, function (FollowUpTask $locked) use ($note, $activityData) {
            $locked->status = FollowUpStatus::Completed;
            $locked->completed_at = now();
            $locked->closing_note = $note;
            $locked->save();

            if ($activityData !== null) {
                $this->activities->createFromFollowUp($locked, $activityData);
            }
        }, 'completed');
    }

    public function cancel(FollowUpTask $task, ?string $note = null): FollowUpTask
    {
        return $this->whilePending($task, function (FollowUpTask $locked) use ($note) {
            $locked->status = FollowUpStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->closing_note = $note;
            $locked->save();
        }, 'cancelled');
    }

    /**
     * Turns a completed follow-up into an activity (type and prospect are
     * copied). Idempotent: converting again returns the existing activity.
     *
     * @return array{0: Activity, 1: bool} the activity and whether it was just created
     */
    public function convertToActivity(FollowUpTask $task, array $data = []): array
    {
        try {
            return DB::transaction(function () use ($task, $data) {
                $locked = FollowUpTask::query()->lockForUpdate()->findOrFail($task->id);

                if ($locked->status !== FollowUpStatus::Completed) {
                    throw BusinessRuleException::invalidFollowUpState(
                        "Only a completed follow-up can be converted into an activity (this one is {$locked->status->value})."
                    );
                }

                $existing = Activity::query()->where('follow_up_task_id', $locked->id)->first();
                if ($existing) {
                    return [$existing->load(['prospect.salesStage', 'followUp']), false];
                }

                $activity = $this->activities->createFromFollowUp($locked, $data);

                return [$activity->load(['prospect.salesStage', 'followUp']), true];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first: return that one.
            $existing = Activity::query()->where('follow_up_task_id', $task->id)->firstOrFail();

            return [$existing->load(['prospect.salesStage', 'followUp']), false];
        }
    }

    /**
     * Re-reads the task with a row lock and only applies $change while it is
     * still pending, so two employees closing the same task cannot both win.
     */
    private function whilePending(FollowUpTask $task, callable $change, string $verb): FollowUpTask
    {
        return DB::transaction(function () use ($task, $change, $verb) {
            $locked = FollowUpTask::query()->lockForUpdate()->findOrFail($task->id);

            if (! $locked->isPending()) {
                throw BusinessRuleException::invalidFollowUpState(
                    "This follow-up is {$locked->status->value} and can no longer be {$verb}."
                );
            }

            $change($locked);

            return $this->loaded($locked);
        });
    }

    private function loaded(FollowUpTask $task): FollowUpTask
    {
        return $task->load(['prospect', 'activity']);
    }
}
