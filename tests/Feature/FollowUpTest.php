<?php

namespace Tests\Feature;

use App\Models\FollowUpTask;
use App\Models\Prospect;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
        $this->prospect = Prospect::factory()->inStage($this->newStage)->create();
        $this->freezeAt('2026-11-10 11:00');
    }

    private function task(array $attributes = []): FollowUpTask
    {
        return FollowUpTask::factory()->for($this->prospect)->create($attributes);
    }

    public function test_it_creates_a_follow_up(): void
    {
        $this->postJson(self::API."/prospects/{$this->prospect->id}/follow-ups", [
            'title' => 'Call back after demo',
            'scheduled_at' => '2026-11-12 09:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.due_state', 'upcoming')
            ->assertJsonPath('data.scheduled_at', '2026-11-12T09:00:00+01:00')
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_follow_up_cannot_be_scheduled_in_the_past(): void
    {
        $this->postJson(self::API."/prospects/{$this->prospect->id}/follow-ups", [
            'title' => 'Too late', 'scheduled_at' => '2026-11-09 09:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);

        // Earlier today is still allowed.
        $this->postJson(self::API."/prospects/{$this->prospect->id}/follow-ups", [
            'title' => 'This morning', 'scheduled_at' => '2026-11-10 08:00',
        ])->assertCreated()->assertJsonPath('data.due_state', 'today');
    }

    public function test_pending_follow_up_can_be_edited(): void
    {
        $task = $this->task();

        $this->putJson(self::API."/follow-ups/{$task->id}", ['title' => 'New title', 'scheduled_at' => '2026-11-20T14:00:00+01:00'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.scheduled_at', '2026-11-20T14:00:00+01:00');

        $this->putJson(self::API."/follow-ups/{$task->id}", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_completing_sets_completed_at_and_is_final(): void
    {
        $task = $this->task();

        $this->postJson(self::API."/follow-ups/{$task->id}/complete", ['closing_note' => 'Reached the manager'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', '2026-11-10T11:00:00+01:00')
            ->assertJsonPath('data.closing_note', 'Reached the manager')
            ->assertJsonPath('data.due_state', null);

        $this->postJson(self::API."/follow-ups/{$task->id}/complete")
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_FOLLOW_UP_STATE');
        $this->postJson(self::API."/follow-ups/{$task->id}/cancel")
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_FOLLOW_UP_STATE');
        $this->putJson(self::API."/follow-ups/{$task->id}", ['title' => 'Rewrite history'])
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_FOLLOW_UP_STATE');

        $this->assertSame('Reached the manager', $task->fresh()->closing_note);
    }

    public function test_cancelling_a_follow_up(): void
    {
        $task = $this->task();

        $this->postJson(self::API."/follow-ups/{$task->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', '2026-11-10T11:00:00+01:00')
            ->assertJsonPath('data.completed_at', null);

        $this->postJson(self::API."/follow-ups/{$task->id}/complete")->assertStatus(422);
    }

    public function test_due_scopes_and_filters(): void
    {
        $overdue = $this->task(['scheduled_at' => '2026-11-08 09:00']);
        $today = $this->task(['scheduled_at' => '2026-11-10 17:00']);
        $soon = $this->task(['scheduled_at' => '2026-11-13 09:00']);
        $later = $this->task(['scheduled_at' => '2026-12-30 09:00']);
        $done = $this->task(['scheduled_at' => '2026-11-09 09:00', 'status' => 'completed', 'completed_at' => '2026-11-09 10:00']);
        $this->task(['scheduled_at' => '2026-11-09 09:00', 'status' => 'cancelled', 'cancelled_at' => '2026-11-09 10:00']);

        $this->getJson(self::API.'/follow-ups?scope=overdue')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.due_state', 'overdue');
        $this->getJson(self::API.'/follow-ups?scope=today')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $today->id);
        $this->getJson(self::API.'/follow-ups?scope=upcoming')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $soon->id);
        $this->getJson(self::API.'/follow-ups?scope=upcoming&days=7')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API.'/follow-ups?status=completed')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $done->id);
        $this->getJson(self::API.'/follow-ups?status=pending')->assertJsonPath('meta.total', 4);
        $this->getJson(self::API.'/follow-ups?date=2026-11-09')->assertJsonPath('meta.total', 2);
        $this->getJson(self::API.'/follow-ups?date_from=2026-11-10&date_to=2026-11-30')->assertJsonPath('meta.total', 2);
        $this->getJson(self::API."/prospects/{$this->prospect->id}/follow-ups")->assertJsonPath('meta.total', 6);
        $this->getJson(self::API."/follow-ups/{$later->id}")->assertOk()->assertJsonPath('data.prospect.id', $this->prospect->id);
        $this->getJson(self::API.'/follow-ups?scope=yesterday')->assertStatus(422)->assertJsonValidationErrors(['scope']);
    }

    public function test_follow_ups_of_deleted_prospects_are_hidden_from_global_lists(): void
    {
        $this->task(['scheduled_at' => '2026-11-10 15:00']);
        $this->prospect->delete();

        $this->getJson(self::API.'/follow-ups?scope=today')->assertJsonPath('meta.total', 0);
    }
}
