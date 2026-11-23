<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\Intervention;
use App\Models\InterventionPriority;
use App\Models\InterventionStatus;
use App\Models\InterventionType;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\Trial;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesPortalFixtures;

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
            ->assertJsonPath('data.recent_activities', [])
            ->assertJsonPath('data.customers.total', 0)
            ->assertJsonPath('data.support.open_tickets', 0)
            ->assertJsonPath('data.operations.appointments_today', 0);
    }

    public function test_dashboard_exposes_customer_support_and_operations_metrics(): void
    {
        $this->setUpPortal(); // pipeline + intervention defaults, clock frozen at 2026-11-16 08:00
        $product = Product::factory()->create();
        [$customer, $account] = $this->customerWith($product);

        // A lead (not converted) never counts as a customer.
        Prospect::factory()->inStage($this->newStage)->create();

        $maintenance = fn (Prospect $p, string $expires, string $status = 'active') => Subscription::query()->create([
            'prospect_id' => $p->id, 'type' => 'maintenance', 'status' => $status, 'starts_at' => '2025-01-01', 'expires_at' => $expires,
        ]);
        $maintenance($customer, '2026-12-31'); // active, not expiring soon
        $soon = Prospect::factory()->inStage($this->wonStage)->create(['converted_at' => now()]);
        $maintenance($soon, '2026-11-30'); // active, expiring within 30 days
        $old = Prospect::factory()->inStage($this->wonStage)->create(['converted_at' => now()]);
        $maintenance($old, '2026-01-31', 'expired');

        $open = fn (string $status, string $priority) => Intervention::query()->create([
            'reference' => 'T-'.uniqid(), 'source' => 'customer',
            'intervention_type_id' => InterventionType::where('code', 'bug')->value('id'),
            'intervention_status_id' => InterventionStatus::where('code', $status)->value('id'),
            'intervention_priority_id' => InterventionPriority::where('code', $priority)->value('id'),
            'prospect_id' => $customer->id, 'title' => 'Issue', 'description' => 'Details',
        ]);
        $open('new', 'p1');
        $open('in_progress', 'p3');
        $open('closed', 'p1'); // closed tickets are excluded

        PortalRequest::query()->create([
            'portal_account_id' => $account->id, 'prospect_id' => $customer->id, 'type' => 'demo', 'status' => 'pending',
            'company' => 'Atlas', 'contact_name' => 'Karim', 'phone' => '0555123456', 'email' => 'k@atlas.test', 'wilaya' => 'Alger', 'city' => 'Alger',
        ]);

        Trial::query()->create([
            'prospect_id' => $customer->id, 'product_id' => $product->id,
            'starts_at' => '2026-11-15 08:00:00', 'ends_at' => '2026-11-20 08:00:00', 'url' => 'https://trial.example.test',
        ]);

        $this->getJson(self::API.'/dashboard')
            ->assertOk()
            ->assertJsonPath('data.customers', ['total' => 3, 'active_maintenance' => 2, 'expiring_soon' => 1, 'expired' => 1])
            ->assertJsonPath('data.support', ['open_tickets' => 2, 'urgent_tickets' => 1, 'pending_requests' => 1])
            ->assertJsonPath('data.operations.active_trials', 1)
            ->assertJsonPath('data.operations.appointments_today', 0);
    }
}
