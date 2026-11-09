<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Tests\TestCase;

/** Native activities, linking to a prospect, and follow-up conversion. */
class ActivityLinkingTest extends TestCase
{
    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
        $this->prospect = Prospect::factory()->inStage($this->newStage)->create();
        $this->freezeAt('2026-11-08 15:00');
    }

    private function createNative(array $extra = []): array
    {
        return $this->postJson(self::API.'/activities', $extra + [
            'type' => 'call',
            'title' => 'Customer called the office',
            'description' => 'Asked about the price list',
            'outcome' => 'Will send a quotation',
            'activity_at' => '2026-11-08T10:00:00+01:00',
        ])->assertCreated()->json('data');
    }

    public function test_a_native_activity_can_be_created_without_a_prospect(): void
    {
        $data = $this->createNative();

        $this->assertNull($data['prospect_id']);
        $this->assertNull($data['prospect']);
        $this->assertFalse($data['is_linked']);
        $this->assertSame('direct', $data['origin']);
        $this->assertSame('Customer called the office', $data['title']);

        $this->getJson(self::API.'/activities?linked=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/activities?linked=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_native_activity_requires_a_title_and_a_valid_type(): void
    {
        $this->postJson(self::API.'/activities', ['type' => 'fax'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'title'], 'errors');

        $this->postJson(self::API.'/activities', ['type' => 'call', 'title' => 'Hi', 'prospect_id' => 99999])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('prospect_id', 'errors');
    }

    public function test_it_can_be_created_already_linked(): void
    {
        $data = $this->createNative(['prospect_id' => $this->prospect->id]);

        $this->assertTrue($data['is_linked']);
        $this->assertSame($this->prospect->id, $data['prospect']['id']);
    }

    public function test_linking_attaches_the_same_row_and_keeps_its_content(): void
    {
        $data = $this->createNative();
        $createdAt = Activity::findOrFail($data['id'])->created_at;

        $this->travelTo(now()->addHour());

        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", ['prospect_id' => $this->prospect->id])
            ->assertOk()
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.is_linked', true)
            ->assertJsonPath('data.prospect.id', $this->prospect->id)
            ->assertJsonPath('data.prospect.sales_stage.name', 'New')
            ->assertJsonPath('data.title', $data['title'])
            ->assertJsonPath('data.description', $data['description'])
            ->assertJsonPath('data.activity_at', $data['activity_at']);

        $this->assertSame(1, Activity::count());
        $this->assertTrue(Activity::findOrFail($data['id'])->created_at->equalTo($createdAt));

        // It now shows up in the prospect timeline.
        $this->getJson(self::API."/prospects/{$this->prospect->id}/activities")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $data['id']);
    }

    public function test_linking_to_the_same_prospect_again_is_a_no_op(): void
    {
        $data = $this->createNative(['prospect_id' => $this->prospect->id]);

        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", ['prospect_id' => $this->prospect->id])
            ->assertOk();
        $this->assertSame(1, Activity::count());
    }

    public function test_changing_the_prospect_requires_confirmation(): void
    {
        $other = Prospect::factory()->inStage($this->demoStage)->create();
        $data = $this->createNative(['prospect_id' => $this->prospect->id]);

        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", ['prospect_id' => $other->id])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVITY_ALREADY_LINKED');
        $this->assertSame($this->prospect->id, Activity::findOrFail($data['id'])->prospect_id);

        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", [
            'prospect_id' => $other->id, 'confirm_relink' => true,
        ])->assertOk()->assertJsonPath('data.prospect.id', $other->id);

        $this->assertSame(1, Activity::count());
        $this->getJson(self::API."/prospects/{$this->prospect->id}/activities")->assertJsonCount(0, 'data');
        $this->getJson(self::API."/prospects/{$other->id}/activities")->assertJsonCount(1, 'data');
    }

    public function test_linking_rejects_unknown_or_deleted_prospects(): void
    {
        $data = $this->createNative();
        $deleted = Prospect::factory()->inStage($this->newStage)->create();
        $deleted->delete();

        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", ['prospect_id' => 99999])
            ->assertStatus(422)->assertJsonValidationErrorFor('prospect_id', 'errors');
        $this->postJson(self::API."/activities/{$data['id']}/link-prospect", ['prospect_id' => $deleted->id])
            ->assertStatus(422);
        $this->postJson(self::API.'/activities/99999/link-prospect', ['prospect_id' => $this->prospect->id])
            ->assertNotFound();
    }

    public function test_legacy_meeting_activities_are_listed_with_appointments(): void
    {
        Activity::factory()->for($this->prospect)->create(['type' => 'meeting']);
        Activity::factory()->for($this->prospect)->create(['type' => 'appointment']);
        Activity::factory()->for($this->prospect)->create(['type' => 'call']);

        $this->getJson(self::API.'/activities?type=appointment')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------- follow-up types

    public function test_follow_up_stores_and_returns_its_activity_type(): void
    {
        $id = $this->postJson(self::API."/prospects/{$this->prospect->id}/follow-ups", [
            'title' => 'Visit', 'scheduled_at' => '2026-11-10T10:00:00+01:00', 'activity_type' => 'appointment',
        ])->assertCreated()->assertJsonPath('data.activity_type', 'appointment')->json('data.id');

        $this->putJson(self::API."/follow-ups/{$id}", ['activity_type' => 'message'])
            ->assertOk()->assertJsonPath('data.activity_type', 'message');

        $this->getJson(self::API."/follow-ups/{$id}")->assertJsonPath('data.activity_type', 'message');

        $this->postJson(self::API."/prospects/{$this->prospect->id}/follow-ups", [
            'title' => 'x', 'scheduled_at' => '2026-11-10T10:00:00+01:00', 'activity_type' => 'fax',
        ])->assertStatus(422)->assertJsonValidationErrorFor('activity_type', 'errors');
    }

    public function test_old_follow_ups_without_a_type_still_work(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create();

        $this->getJson(self::API."/follow-ups/{$task->id}")->assertOk()->assertJsonPath('data.activity_type', null);
    }

    public function test_completing_with_create_activity_copies_type_and_prospect(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create([
            'title' => 'Call about the quote', 'activity_type' => 'call',
        ]);

        $response = $this->postJson(self::API."/follow-ups/{$task->id}/complete", [
            'closing_note' => 'Reached the manager',
            'create_activity' => true,
            'activity_description' => 'Discussed the quotation',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $activity = Activity::where('follow_up_task_id', $task->id)->sole();
        $this->assertSame('call', $activity->type->value);
        $this->assertSame($this->prospect->id, $activity->prospect_id);
        $this->assertSame('Call about the quote', $activity->title);
        $this->assertSame('Discussed the quotation', $activity->description);
        $this->assertSame('Reached the manager', $activity->outcome);
        $response->assertJsonPath('data.activity_id', $activity->id);
    }

    public function test_completing_without_create_activity_keeps_the_old_behaviour(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create(['activity_type' => 'call']);

        $this->postJson(self::API."/follow-ups/{$task->id}/complete", ['closing_note' => 'ok'])->assertOk();

        $this->assertSame(0, Activity::count());
    }

    public function test_repeated_completion_never_duplicates_the_activity(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create(['activity_type' => 'message']);

        $this->postJson(self::API."/follow-ups/{$task->id}/complete", ['create_activity' => true])->assertOk();
        $this->postJson(self::API."/follow-ups/{$task->id}/complete", ['create_activity' => true])
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_FOLLOW_UP_STATE');

        $this->assertSame(1, Activity::where('follow_up_task_id', $task->id)->count());
    }

    public function test_convert_to_activity_is_idempotent_and_needs_a_completed_follow_up(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create(['activity_type' => 'appointment']);

        $this->postJson(self::API."/follow-ups/{$task->id}/convert-to-activity")
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_FOLLOW_UP_STATE');

        $this->postJson(self::API."/follow-ups/{$task->id}/complete")->assertOk();

        $first = $this->postJson(self::API."/follow-ups/{$task->id}/convert-to-activity")
            ->assertCreated()
            ->assertJsonPath('data.type', 'appointment')
            ->assertJsonPath('data.origin', 'follow_up')
            ->assertJsonPath('data.follow_up_id', $task->id)
            ->assertJsonPath('data.prospect.id', $this->prospect->id)
            ->json('data.id');

        $this->postJson(self::API."/follow-ups/{$task->id}/convert-to-activity")
            ->assertOk()->assertJsonPath('data.id', $first);

        $this->assertSame(1, Activity::where('follow_up_task_id', $task->id)->count());
    }

    public function test_a_legacy_follow_up_without_type_converts_to_other(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->completed()->create();

        $this->postJson(self::API."/follow-ups/{$task->id}/convert-to-activity")
            ->assertCreated()->assertJsonPath('data.type', 'other');
    }

    public function test_a_legacy_follow_up_takes_the_type_chosen_at_completion(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create();

        $this->postJson(self::API."/follow-ups/{$task->id}/complete", [
            'create_activity' => true, 'activity_type' => 'message',
        ])->assertOk();

        $this->assertSame('message', Activity::where('follow_up_task_id', $task->id)->sole()->type->value);
    }

    public function test_a_converted_activity_cannot_be_moved_to_another_prospect(): void
    {
        $task = FollowUpTask::factory()->for($this->prospect)->create(['activity_type' => 'call']);
        $this->postJson(self::API."/follow-ups/{$task->id}/complete", ['create_activity' => true])->assertOk();
        $activity = Activity::where('follow_up_task_id', $task->id)->sole();
        $other = Prospect::factory()->inStage($this->newStage)->create();

        $this->postJson(self::API."/activities/{$activity->id}/link-prospect", [
            'prospect_id' => $other->id, 'confirm_relink' => true,
        ])->assertStatus(422)->assertJsonPath('error_code', 'ACTIVITY_LINK_LOCKED');
    }

    public function test_unlinked_activities_appear_in_the_dashboard_recent_list(): void
    {
        $this->createNative();

        $this->getJson(self::API.'/dashboard')->assertOk()->assertJsonCount(1, 'data.recent_activities');
    }
}
