<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use RuntimeException;
use Tests\TestCase;

class StageTransitionTest extends TestCase
{
    private int $prospectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
        $this->prospectId = $this->postJson(self::API.'/prospects', ['name' => 'Pipeline Co'])->json('data.id');
    }

    private function changeStage(array $payload)
    {
        return $this->postJson(self::API."/prospects/{$this->prospectId}/change-stage", $payload);
    }

    public function test_open_to_open_transition_updates_prospect_and_records_history(): void
    {
        $this->changeStage(['sales_stage_id' => $this->demoStage->id])
            ->assertOk()
            ->assertJsonPath('message', 'Prospect stage changed successfully.')
            ->assertJsonPath('data.prospect.sales_stage_id', $this->demoStage->id)
            ->assertJsonPath('data.stage_history.from_stage.id', $this->newStage->id)
            ->assertJsonPath('data.stage_history.to_stage.id', $this->demoStage->id)
            ->assertJsonPath('data.stage_history.loss_reason', null);

        $this->assertSame(2, ProspectStageHistory::where('prospect_id', $this->prospectId)->count());
    }

    public function test_won_transition_is_recorded(): void
    {
        $this->changeStage(['sales_stage_id' => $this->wonStage->id])
            ->assertOk()
            ->assertJsonPath('data.prospect.sales_stage.stage_type', 'won');

        $this->assertDatabaseHas('prospect_stage_histories', [
            'prospect_id' => $this->prospectId, 'from_stage_id' => $this->newStage->id, 'to_stage_id' => $this->wonStage->id,
        ]);
    }

    public function test_lost_transition_requires_a_loss_reason(): void
    {
        $this->changeStage(['sales_stage_id' => $this->lostStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loss_reason_id']);

        $this->assertSame($this->newStage->id, Prospect::find($this->prospectId)->sales_stage_id);
        $this->assertSame(1, ProspectStageHistory::count());
    }

    public function test_lost_transition_requires_a_note_when_the_reason_demands_it(): void
    {
        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->otherReason->id, 'loss_note' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loss_note']);

        $this->changeStage([
            'sales_stage_id' => $this->lostStage->id,
            'loss_reason_id' => $this->otherReason->id,
            'loss_note' => 'The customer selected a competitor.',
        ])
            ->assertOk()
            ->assertJsonPath('data.prospect.loss_reason.id', $this->otherReason->id)
            ->assertJsonPath('data.prospect.loss_note', 'The customer selected a competitor.')
            ->assertJsonPath('data.stage_history.loss_reason.id', $this->otherReason->id)
            ->assertJsonPath('data.stage_history.loss_note', 'The customer selected a competitor.');
    }

    public function test_lost_transition_without_note_requirement_succeeds(): void
    {
        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id])
            ->assertOk()
            ->assertJsonPath('data.prospect.loss_reason_id', $this->priceReason->id);
    }

    public function test_inactive_loss_reason_is_rejected(): void
    {
        $this->priceReason->update(['is_active' => false]);

        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loss_reason_id']);
    }

    public function test_inactive_target_stage_is_rejected(): void
    {
        $this->demoStage->update(['is_active' => false]);

        $this->changeStage(['sales_stage_id' => $this->demoStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sales_stage_id']);
    }

    public function test_unknown_stage_and_same_stage_are_rejected(): void
    {
        $this->changeStage(['sales_stage_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['sales_stage_id']);

        $this->changeStage(['sales_stage_id' => $this->newStage->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STAGE_TRANSITION');
    }

    public function test_loss_information_is_refused_for_non_lost_stages(): void
    {
        $this->changeStage(['sales_stage_id' => $this->demoStage->id, 'loss_reason_id' => $this->priceReason->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loss_reason_id']);
    }

    public function test_leaving_a_lost_stage_clears_current_loss_info_but_keeps_history(): void
    {
        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id])->assertOk();

        $this->changeStage(['sales_stage_id' => $this->demoStage->id])
            ->assertOk()
            ->assertJsonPath('data.prospect.loss_reason_id', null)
            ->assertJsonPath('data.prospect.loss_note', null);

        $this->assertDatabaseHas('prospect_stage_histories', [
            'prospect_id' => $this->prospectId, 'to_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id,
        ]);
    }

    public function test_reopening_closed_prospects_can_be_disabled_by_configuration(): void
    {
        config(['crm.stage_transitions.allow_reopening_closed' => false]);
        $this->changeStage(['sales_stage_id' => $this->wonStage->id])->assertOk();

        $this->changeStage(['sales_stage_id' => $this->demoStage->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STAGE_TRANSITION');
    }

    public function test_transition_is_rolled_back_when_history_cannot_be_written(): void
    {
        ProspectStageHistory::creating(fn () => throw new RuntimeException('simulated failure'));

        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id])
            ->assertStatus(500);

        $prospect = Prospect::find($this->prospectId);
        $this->assertSame($this->newStage->id, $prospect->sales_stage_id);
        $this->assertNull($prospect->loss_reason_id);
    }

    public function test_stage_history_endpoint_keeps_deactivated_reasons_and_stages_readable(): void
    {
        $this->changeStage(['sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id])->assertOk();
        $this->priceReason->update(['is_active' => false]);
        $this->lostStage->update(['is_active' => false]);

        $this->getJson(self::API."/prospects/{$this->prospectId}/stage-history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.to_stage.name', 'Lost')
            ->assertJsonPath('data.0.to_stage.is_active', false)
            ->assertJsonPath('data.0.loss_reason.name', 'Too expensive')
            ->assertJsonPath('data.0.loss_reason.is_active', false)
            ->assertJsonPath('data.1.from_stage', null)
            ->assertJsonPath('meta.total', 2);

        $this->getJson(self::API."/prospects/{$this->prospectId}")
            ->assertJsonPath('data.loss_reason.name', 'Too expensive');
    }
}
