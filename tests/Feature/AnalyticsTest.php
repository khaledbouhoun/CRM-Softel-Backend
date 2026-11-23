<?php

namespace Tests\Feature;

use App\Models\Prospect;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    public function test_analytics_returns_the_structured_payload(): void
    {
        $this->createPipeline();

        $this->getJson(self::API.'/analytics')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.range', 'month')
            ->assertJsonStructure(['data' => [
                'sales' => ['funnel', 'loss_reasons', 'sources', 'by_city', 'avg_sales_cycle_days'],
                'revenue_maintenance' => [
                    'total_customers', 'active_maintenance_count', 'expired_maintenance_count',
                    'retention_rate', 'expiring_next_60_days', 'products_distribution', 'avg_pcs_per_customer',
                ],
                'support' => ['total_tickets', 'resolved_tickets', 'resolution_rate', 'avg_resolution_hours', 'by_type', 'demo_conversion'],
                'team' => ['leaderboard'],
            ]]);
    }

    public function test_funnel_loss_reasons_sources_and_cities_follow_the_period(): void
    {
        $this->createPipeline();
        $this->freezeAt('2026-11-10 11:00');

        $create = fn (string $name, int $stage, array $extra = []) => $this->postJson(
            self::API.'/prospects', ['name' => $name, 'sales_stage_id' => $stage, ...$extra]
        )->assertCreated()->json('data.id');

        $a = $create('A', $this->newStage->id, ['city' => 'Alger']);
        Prospect::whereKey($a)->update(['source' => 'facebook']); // option validation is not under test here
        $create('B', $this->newStage->id, ['city' => 'Alger']);
        $demo = $create('C', $this->newStage->id, ['city' => 'Oran']);
        $this->postJson(self::API."/prospects/{$demo}/change-stage", ['sales_stage_id' => $this->demoStage->id])->assertOk();
        $won = $create('D', $this->newStage->id, ['city' => 'Oran']);
        $this->postJson(self::API."/prospects/{$won}/change-stage", ['sales_stage_id' => $this->wonStage->id])->assertOk();
        $create('E', $this->lostStage->id, ['loss_reason_id' => $this->priceReason->id]);

        $deleted = $create('Gone', $this->newStage->id);
        Prospect::find($deleted)->delete();

        $data = $this->getJson(self::API.'/analytics?range=month')->assertOk()->json('data');

        // Funnel: New (all 5 live prospects minus the lost one that never was "New"), Demo, Won.
        $funnel = collect($data['sales']['funnel'])->keyBy('stage');
        $this->assertSame(100.0, $funnel['New']['rate']);
        $this->assertSame(2, $funnel['Demo']['count']); // C and D passed through/at Demo or beyond
        $this->assertSame(1, $funnel['Won']['count']);

        $this->assertSame(1, $data['sales']['loss_reasons'][0]['count']);
        $this->assertSame('Too expensive', $data['sales']['loss_reasons'][0]['reason']);
        $this->assertSame(100.0, $data['sales']['loss_reasons'][0]['percentage']);

        $this->assertSame('Alger', $data['sales']['by_city'][0]['city']);
        $this->assertSame(2, $data['sales']['by_city'][0]['count']);
        $this->assertContains('facebook', array_column($data['sales']['sources'], 'source'));

        // A different period excludes everything created now.
        $past = $this->getJson(self::API.'/analytics?from=2025-01-01&to=2025-12-31')->assertOk()->json('data');
        $this->assertSame('custom', $past['range']);
        $this->assertSame([], $past['sales']['by_city']);
    }

    public function test_invalid_range_is_rejected(): void
    {
        $this->getJson(self::API.'/analytics?range=decade')->assertStatus(422);
        $this->getJson(self::API.'/analytics?from=2026-02-01&to=2026-01-01')->assertStatus(422);
    }
}
