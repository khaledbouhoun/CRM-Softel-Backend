<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use App\Models\ProspectFormOption;
use Tests\TestCase;

class ActivityFollowUpLinkingTest extends TestCase
{
    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
        $this->prospect = Prospect::factory()->inStage($this->newStage)->create();
    }

    public function test_activity_can_be_linked_to_a_follow_up(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create([
            'title' => 'Follow up on quotation',
            'activity_type' => 'call',
        ]);

        $activity = Activity::create([
            'type' => 'call',
            'title' => 'Customer callback',
            'description' => 'Discussed details',
            'activity_at' => now(),
        ]);

        $this->assertNull($activity->follow_up_task_id);
        $this->assertNull($activity->prospect_id);

        $response = $this->postJson(self::API."/activities/{$activity->id}/link-follow-up", [
            'follow_up_id' => $task->id,
        ])->assertOk();

        $response->assertJsonPath('data.follow_up_id', $task->id)
            ->assertJsonPath('data.prospect_id', $this->prospect->id)
            ->assertJsonPath('data.is_linked', true)
            ->assertJsonPath('data.is_linked_to_follow_up', true);

        $activity->refresh();
        $this->assertSame($task->id, $activity->follow_up_task_id);
        $this->assertSame($this->prospect->id, $activity->prospect_id);
    }

    public function test_linking_fails_when_activity_and_follow_up_belong_to_different_prospects(): void
    {
        $otherProspect = Prospect::factory()->inStage($this->newStage)->create();
        $task = FollowUpTask::factory()->for($otherProspect)->create(['title' => 'Task']);

        $activity = Activity::create([
            'type' => 'call',
            'title' => 'Call for prospect 1',
            'description' => 'Desc',
            'activity_at' => now(),
            'prospect_id' => $this->prospect->id,
        ]);

        $this->postJson(self::API."/activities/{$activity->id}/link-follow-up", [
            'follow_up_id' => $task->id,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['follow_up_id']);
    }

    public function test_an_activity_already_linked_to_a_follow_up_cannot_be_relinked(): void
    {
        $task1 = FollowUpTask::factory()->for($this->prospect)->create(['title' => 'Task 1']);
        $task2 = FollowUpTask::factory()->for($this->prospect)->create(['title' => 'Task 2']);

        $activity = Activity::create([
            'type' => 'call',
            'title' => 'Call',
            'description' => 'Desc',
            'activity_at' => now(),
            'prospect_id' => $this->prospect->id,
            'follow_up_task_id' => $task1->id,
        ]);

        // Same follow-up is an idempotent no-op
        $this->postJson(self::API."/activities/{$activity->id}/link-follow-up", [
            'follow_up_id' => $task1->id,
        ])->assertOk();

        // Different follow-up is rejected
        $this->postJson(self::API."/activities/{$activity->id}/link-follow-up", [
            'follow_up_id' => $task2->id,
        ])->assertStatus(422)
          ->assertJsonPath('error_code', 'ACTIVITY_LINK_LOCKED');
    }

    public function test_cannot_change_prospect_association_when_prospect_has_activities_or_follow_ups(): void
    {
        $otherProspect = Prospect::factory()->inStage($this->newStage)->create();
        $followUp = FollowUpTask::factory()->for($this->prospect)->create(['title' => 'Follow up']);

        $activity = Activity::create([
            'type' => 'call',
            'title' => 'Activity',
            'description' => 'Desc',
            'activity_at' => now(),
            'prospect_id' => $this->prospect->id,
        ]);

        // Attempting to move the activity directly to another prospect is locked
        $this->postJson(self::API."/activities/{$activity->id}/link-prospect", [
            'prospect_id' => $otherProspect->id,
            'confirm_relink' => true,
        ])->assertStatus(422)
          ->assertJsonPath('error_code', 'ACTIVITY_LINK_LOCKED');
    }
}
