<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activities are an append-only communication log. The only change allowed
 * after creation is linking an activity without a follow-up to an existing follow-up.
 */
class ActivityService
{
  public function create(Prospect $prospect, array $data): Activity
  {
    $activity = $this->build($data);
    $activity->prospect()->associate($prospect);
    if (!empty($data['follow_up_task_id']) || !empty($data['follow_up_id'])) {
      $followUpId = $data['follow_up_task_id'] ?? $data['follow_up_id'];
      $followUp = FollowUpTask::query()->findOrFail($followUpId);
      if ($followUp->prospect_id !== $prospect->id) {
        throw ValidationException::withMessages([
          'follow_up_id' => ['The activity and its selected follow-up must belong to the same prospect.'],
        ]);
      }
      $activity->follow_up_task_id = $followUp->id;
    }
    $activity->linked_at = now();
    $activity->save();

    return $this->fresh($activity);
  }

  /** Activity created on its own; link to follow-up is optional. */
  public function createNative(array $data): Activity
  {
    $activity = $this->build($data);

    if (!empty($data['follow_up_task_id']) || !empty($data['follow_up_id'])) {
      $followUpId = $data['follow_up_task_id'] ?? $data['follow_up_id'];
      $followUp = FollowUpTask::query()->findOrFail($followUpId);

      if (!empty($data['prospect_id']) && (int) $data['prospect_id'] !== $followUp->prospect_id) {
        throw ValidationException::withMessages([
          'follow_up_id' => ['The activity and its selected follow-up must belong to the same prospect.'],
        ]);
      }

      $activity->follow_up_task_id = $followUp->id;
      $activity->prospect_id = $followUp->prospect_id;
      $activity->linked_at = now();
    } elseif (!empty($data['prospect_id'])) {
      $activity->prospect()->associate(Prospect::query()->findOrFail($data['prospect_id']));
      $activity->linked_at = now();
    }

    $activity->save();

    return $this->fresh($activity);
  }

  /**
   * Records the activity a follow-up turns into.
   *
   * @param  array{activity_type?: ?string, activity_description?: ?string, activity_outcome?: ?string, activity_at?: ?string}  $data
   */
  public function createFromFollowUp(FollowUpTask $task, array $data = []): Activity
  {
    $type = $data['activity_type'] ?? $task->activity_type?->value ?? 'other';

    $activity = new Activity([
      'type' => $type,
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
   * Links an activity to an existing follow-up.
   *
   * Rules:
   *  - An activity and its selected follow-up must belong to the same prospect.
   *  - Changing a prospect's association once that prospect has any follow-ups or activities is prevented.
   *  - If an activity already has a follow-up linked, its link cannot be changed.
   */
  public function linkFollowUp(Activity $activity, FollowUpTask $followUp): Activity
  {
    return DB::transaction(function () use ($activity, $followUp) {
      $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);
      $lockedFollowUp = FollowUpTask::query()->lockForUpdate()->findOrFail($followUp->id);

      if ($locked->follow_up_task_id !== null) {
        if ($locked->follow_up_task_id === $lockedFollowUp->id) {
          return $this->fresh($locked);
        }
        throw BusinessRuleException::activityLinkLocked(
          'This activity is already linked to a follow-up and its link cannot be changed.'
        );
      }

      if ($locked->prospect_id !== null && $locked->prospect_id !== $lockedFollowUp->prospect_id) {
        throw ValidationException::withMessages([
          'follow_up_id' => ['The activity and its selected follow-up must belong to the same prospect.'],
        ]);
      }

      // Prevent changing prospect association once that prospect has any follow-ups or activities
      if ($locked->prospect_id !== null) {
        $prospect = Prospect::withTrashed()->find($locked->prospect_id);
        if ($prospect && ($prospect->activities()->whereKeyNot($locked->id)->exists() || $prospect->followUps()->exists())) {
          if ($locked->prospect_id !== $lockedFollowUp->prospect_id) {
            throw BusinessRuleException::activityLinkLocked(
              'Cannot change a prospect\'s association once that prospect has any follow-ups or activities.'
            );
          }
        }
      }

      $locked->follow_up_task_id = $lockedFollowUp->id;
      $locked->prospect_id = $lockedFollowUp->prospect_id;
      $locked->linked_at = now();
      $locked->save();

      return $this->fresh($locked);
    });
  }

  /**
   * Legacy direct linking to a prospect.
   * Enforces that once a prospect has any follow-ups or activities, changing association is blocked.
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

      // Prevent changing a prospect's association once that prospect has any follow-ups or activities
      if ($locked->prospect_id !== null) {
        $currentProspect = Prospect::withTrashed()->find($locked->prospect_id);
        if ($currentProspect && ($currentProspect->activities()->whereKeyNot($locked->id)->exists() || $currentProspect->followUps()->exists())) {
          throw BusinessRuleException::activityLinkLocked(
            'Cannot change a prospect\'s association once that prospect has any follow-ups or activities.'
          );
        }

        if (!$confirmRelink) {
          throw BusinessRuleException::activityAlreadyLinked(
            'This activity is already linked to another prospect. Send confirm_relink=true to move it.'
          );
        }
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
      'description' => $data['description'] ?? '',
      'outcome' => $data['outcome'] ?? null,
      'activity_at' => $data['activity_at'] ?? now(),
    ]);
  }

  private function fresh(Activity $activity): Activity
  {
    return $activity->refresh()->load(['prospect.salesStage', 'followUp.prospect.salesStage']);
  }
}
