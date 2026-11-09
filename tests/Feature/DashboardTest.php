<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_statistics_are_computed_from_the_database(): void
    {
        $this->createPipeline();
        $this->freezeAt('2026-11-10 11:00');

        // Via the API so stage history exists: 2 new, 1 demo, 1 won, 2 lost.
        $create = fn (string $name, int $stage, array $extra = []) => $this->postJson(
            self::API.'/prospects', ['name' => $name, 'sales_stage_id' => $stage, ...$extra]
        )->assertCreated()->json('data.id');

        $a = $create('A', $this->newStage->id);
        $create('B', $this->newStage->id);
        $create('C', $this->demoStage->id);
        $won = $create('D', $this->demoStage->id);
        $this->postJson(self::API."/prospects/{$won}/change-stage", ['sales_stage_id' => $this->wonStage->id])->assertOk();
        $create('E', $this->lostStage->id, ['loss_reason_id' => $this->priceReason->id]);
        $create('F', $this->lostStage->id, ['loss_reason_id' => $this->priceReason->id]);

        // Soft-deleted prospect is excluded everywhere.
        $deleted = $create('Deleted', $this->newStage->id);
        FollowUpTask::factory()->create(['prospect_id' => $deleted, 'scheduled_at' => '2026-11-10 15:00']);
        Prospect::find($deleted)->delete();

        FollowUpTask::factory()->create(['prospect_id' => $a, 'scheduled_at' => '2026-11-09 09:00']); // overdue
        FollowUpTask::factory()->create(['prospect_id' => $a, 'scheduled_at' => '2026-11-10 16:00']); // today
        FollowUpTask::factory()->create(['prospect_id' => $a, 'scheduled_at' => '2026-11-12 09:00']); // upcoming (<= 7 days)
        FollowUpTask::factory()->create(['prospect_id' => $a, 'scheduled_at' => '2026-12-25 09:00']); // beyond window
        FollowUpTask::factory()->completed()->create(['prospect_id' => $a, 'scheduled_at' => '2026-11-09 09:00']);

        Activity::factory()->count(3)->create(['prospect_id' => $a]);

        $response = $this->getJson(self::API.'/dashboard')->assertOk();

        $response->assertJsonPath('data.prospects', ['total' => 6, 'open' => 3, 'won' => 1, 'lost' => 2])
            ->assertJsonPath('data.follow_ups.overdue', 1)
            ->assertJsonPath('data.follow_ups.today', 1)
            ->assertJsonPath('data.follow_ups.upcoming', 1)
            ->assertJsonPath('data.follow_ups.upcoming_window_days', 7)
            ->assertJsonPath('data.this_month.won', 1)
            ->assertJsonPath('data.this_month.lost', 2)
            ->assertJsonPath('data.lost_by_reason.0.count', 2)
            ->assertJsonCount(3, 'data.recent_activities')
            ->assertJsonCount(4, 'data.prospects_by_stage');

        $byStage = collect($response->json('data.prospects_by_stage'))->pluck('count', 'name')->all();
        $this->assertSame(['New' => 2, 'Demo' => 1, 'Won' => 1, 'Lost' => 2], $byStage);
    }

    public function test_dashboard_works_on_an_empty_database(): void
    {
        $this->getJson(self::API.'/dashboard')
            ->assertOk()
            ->assertJsonPath('data.prospects.total', 0)
            ->assertJsonPath('data.recent_activities', []);
    }
}
