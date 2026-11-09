<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Prospect;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
        $this->prospect = Prospect::factory()->inStage($this->newStage)->create();
    }

    public function test_it_creates_an_activity(): void
    {
        $this->freezeAt('2026-11-01 15:00');

        $this->postJson(self::API."/prospects/{$this->prospect->id}/activities", [
            'type' => 'meeting',
            'description' => 'Product demonstration on site',
            'outcome' => 'Interested, wants a quotation',
            'activity_at' => '2026-11-01T10:30:00+01:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'meeting')
            ->assertJsonPath('data.prospect.id', $this->prospect->id)
            ->assertJsonPath('data.activity_at', '2026-11-01T10:30:00+01:00');
    }

    public function test_activity_time_with_another_offset_is_converted_to_business_time(): void
    {
        $this->freezeAt('2026-11-01 15:00');

        $this->postJson(self::API."/prospects/{$this->prospect->id}/activities", [
            'type' => 'call', 'description' => 'Call', 'activity_at' => '2026-11-01T09:30:00Z',
        ])->assertCreated()->assertJsonPath('data.activity_at', '2026-11-01T10:30:00+01:00');

        $this->assertDatabaseHas('activities', ['activity_at' => '2026-11-01 10:30:00']);
    }

    public function test_activity_date_defaults_to_now(): void
    {
        $now = $this->freezeAt('2026-11-01 15:00');

        $this->postJson(self::API."/prospects/{$this->prospect->id}/activities", ['type' => 'note', 'description' => 'Note'])
            ->assertCreated()
            ->assertJsonPath('data.activity_at', $now->toIso8601String());
    }

    public function test_it_validates_activity_input(): void
    {
        $this->postJson(self::API."/prospects/{$this->prospect->id}/activities", [
            'type' => 'fax', 'description' => '', 'activity_at' => now()->addDays(3)->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'description', 'activity_at']);

        $this->postJson(self::API.'/prospects/999999/activities', ['type' => 'call', 'description' => 'x y'])
            ->assertNotFound();
    }

    public function test_activities_cannot_be_deleted_through_the_api(): void
    {
        $activity = Activity::factory()->for($this->prospect)->create();

        $this->deleteJson(self::API."/activities/{$activity->id}")->assertStatus(405);
        $this->deleteJson(self::API."/prospects/{$this->prospect->id}/activities")
            ->assertStatus(405)
            ->assertJsonPath('error_code', 'METHOD_NOT_ALLOWED');
        $this->assertDatabaseHas('activities', ['id' => $activity->id]);
    }

    public function test_lists_activities_with_filters(): void
    {
        $other = Prospect::factory()->inStage($this->newStage)->create();
        Activity::factory()->for($this->prospect)->create(['type' => 'call', 'activity_at' => '2026-10-01 09:00']);
        Activity::factory()->for($this->prospect)->create(['type' => 'meeting', 'activity_at' => '2026-10-15 09:00']);
        Activity::factory()->for($other)->create(['type' => 'call', 'activity_at' => '2026-10-20 09:00']);
        $deleted = Prospect::factory()->inStage($this->newStage)->create();
        Activity::factory()->for($deleted)->create();
        $deleted->delete();

        $this->getJson(self::API.'/activities')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.activity_at', '2026-10-20T09:00:00+01:00');
        $this->getJson(self::API.'/activities?type=call')->assertJsonPath('meta.total', 2);
        $this->getJson(self::API."/activities?prospect_id={$this->prospect->id}")->assertJsonPath('meta.total', 2);
        $this->getJson(self::API.'/activities?date_from=2026-10-10&date_to=2026-10-15')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API."/prospects/{$this->prospect->id}/activities?direction=asc")
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'call');
    }
}
