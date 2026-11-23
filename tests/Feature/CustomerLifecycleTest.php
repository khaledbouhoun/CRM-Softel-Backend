<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\FollowUpTask;
use App\Models\PortalAccount;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\Subscription;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class CustomerLifecycleTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $this->product = Product::factory()->create(['name' => 'GestCom', 'demo_url' => 'https://trial.example.test/abc']);
    }

    private function type(string $code): AppointmentType
    {
        return AppointmentType::query()->where('code', $code)->firstOrFail();
    }

    private function prospect(): Prospect
    {
        return Prospect::factory()->inStage($this->demoStage)->create(['phone' => '0555123456']);
    }

    // ---- conversion

    public function test_conversion_keeps_the_prospect_history_and_the_portal_account(): void
    {
        $prospect = $this->prospect();
        $prospect->products()->attach($this->product->id);
        $activity = Activity::factory()->create(['prospect_id' => $prospect->id]);
        $followUp = FollowUpTask::factory()->create(['prospect_id' => $prospect->id]);
        [$account, $headers] = $this->signedIn($prospect);
        $this->assertFalse($this->getJson(self::PORTAL.'/me', $headers)->json('data.is_customer'));

        $this->postJson(self::API."/prospects/{$prospect->id}/convert", ['product_ids' => [$this->product->id], 'note' => 'Signed contract'], ['X-Employee-Name' => 'Samir'])
            ->assertOk()->assertJsonPath('data.id', $prospect->id)->assertJsonPath('data.is_customer', true)
            ->assertJsonPath('data.converted_by', 'Samir')->assertJsonPath('data.sales_stage.id', $this->wonStage->id);

        $fresh = $prospect->fresh();
        $this->assertSame(1, Prospect::query()->whereKey($prospect->id)->count());
        $this->assertTrue(Activity::query()->whereKey($activity->id)->where('prospect_id', $prospect->id)->exists());
        $this->assertTrue(FollowUpTask::query()->whereKey($followUp->id)->where('prospect_id', $prospect->id)->exists());
        $history = ProspectStageHistory::query()->where('prospect_id', $prospect->id)->get();
        $this->assertSame($this->wonStage->id, $history->last()->to_stage_id);
        $this->assertNotNull($fresh->products()->withPivot('purchased_at')->first()->pivot->purchased_at);

        // Same identity, same token: now sees customer features.
        $this->getJson(self::PORTAL.'/me', $headers)->assertOk()->assertJsonPath('data.is_customer', true)->assertJsonPath('data.id', $account->id);
        $this->getJson(self::PORTAL.'/products', $headers)->assertJsonCount(1, 'data');
        $this->getJson(self::API."/customers/{$prospect->id}")->assertOk()->assertJsonPath('data.portal_accounts.0.email', $account->email);
        $this->getJson(self::API.'/customers')->assertJsonPath('meta.total', 1);
    }

    public function test_second_conversion_is_refused_and_unconverted_prospects_are_not_customers(): void
    {
        $prospect = $this->prospect();
        $this->postJson(self::API."/prospects/{$prospect->id}/convert", [])->assertOk();
        $this->postJson(self::API."/prospects/{$prospect->id}/convert", [])->assertStatus(409)->assertJsonPath('error_code', 'ALREADY_CUSTOMER');

        $other = $this->prospect();
        $this->getJson(self::API.'/customers')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API."/prospects/{$other->id}")->assertJsonPath('data.is_customer', false);
    }

    // ---- training

    public function test_first_training_is_per_customer_and_product_and_whatsapp_is_only_prefilled(): void
    {
        $prospect = $this->prospect();
        $other = Product::factory()->create(['name' => 'GestPaie']);
        $payload = fn (Product $p, string $at) => [
            'prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('training')->id, 'product_id' => $p->id,
            'starts_at' => $at, 'ends_at' => date('Y-m-d H:i:s', strtotime($at) + 7200), 'assigned_employee' => 'Samir',
            'send_whatsapp' => true, 'resource_url' => 'https://drive.example.test/guide.pdf',
        ];

        $first = $this->postJson(self::API.'/appointments', $payload($this->product, '2026-11-18 09:00:00'))->assertCreated()
            ->assertJsonPath('data.training_kind', 'first')->assertJsonPath('data.status', 'confirmed');
        $this->assertStringStartsWith('https://wa.me/213555123456?text=', $first->json('data.whatsapp.url'));
        $this->assertFalse($first->json('data.whatsapp.delivery_confirmed'));
        $this->assertStringContainsString('guide.pdf', $first->json('data.whatsapp.message'));

        $this->postJson(self::API.'/appointments', $payload($this->product, '2026-11-19 09:00:00'))->assertCreated()->assertJsonPath('data.training_kind', 'follow_up');
        $this->postJson(self::API.'/appointments', $payload($other, '2026-11-22 09:00:00'))->assertCreated()->assertJsonPath('data.training_kind', 'first');
        $this->assertSame(0, Activity::count());   // scheduled sessions are NOT activities
    }

    public function test_training_conflicts_for_same_employee_or_customer_are_explained_and_nothing_is_saved(): void
    {
        $prospect = $this->prospect();
        $payload = fn (array $o = []) => $o + [
            'prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('training')->id, 'product_id' => $this->product->id,
            'starts_at' => '2026-11-18 09:00:00', 'ends_at' => '2026-11-18 11:00:00', 'assigned_employee' => 'Samir',
        ];
        $this->postJson(self::API.'/appointments', $payload())->assertCreated();

        $this->postJson(self::API.'/appointments', $payload(['starts_at' => '2026-11-18 10:00:00', 'ends_at' => '2026-11-18 12:00:00']))
            ->assertStatus(409)->assertJsonPath('error_code', 'APPOINTMENT_CONFLICT')->assertJsonPath('errors.conflicts.0.reason', 'employee_busy');
        $this->postJson(self::API.'/appointments', $payload(['starts_at' => '2026-11-18 10:00:00', 'ends_at' => '2026-11-18 12:00:00', 'assigned_employee' => 'Lina']))
            ->assertStatus(409)->assertJsonPath('errors.conflicts.0.reason', 'customer_busy');
        $this->postJson(self::API.'/appointments', $payload(['starts_at' => '2026-11-18 11:00:00', 'ends_at' => '2026-11-18 12:00:00']))->assertCreated();  // back-to-back is fine
        $this->assertSame(2, Appointment::count());
    }

    public function test_accompaniment_series_defaults_to_four_weekly_sessions_all_or_nothing(): void
    {
        $prospect = $this->prospect();
        $payload = fn (array $o = []) => $o + [
            'prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('accompaniment')->id, 'product_id' => $this->product->id,
            'starts_at' => '2026-11-17 14:00:00', 'ends_at' => '2026-11-17 16:00:00', 'assigned_employee' => 'Samir',
        ];

        // Block the 3rd occurrence (2026-12-01) for the same trainer.
        $this->postJson(self::API.'/appointments', [
            'prospect_id' => $this->prospect()->id, 'appointment_type_id' => $this->type('meeting')->id,
            'starts_at' => '2026-12-01 15:00:00', 'ends_at' => '2026-12-01 16:00:00', 'assigned_employee' => 'Samir',
        ])->assertCreated();

        $this->postJson(self::API.'/appointments', $payload())->assertStatus(409)->assertJsonPath('errors.conflicts.0.occurrence', 3);
        $this->assertSame(1, Appointment::count());                       // no partial series
        $this->assertDatabaseCount('appointment_series', 0);

        $ok = $this->postJson(self::API.'/appointments', $payload(['assigned_employee' => 'Lina']))->assertCreated();
        $dates = collect($ok->json('data.appointments'))->map(fn ($a) => substr($a['starts_at'], 0, 16))->all();
        $this->assertSame(['2026-11-17T14:00', '2026-11-24T14:00', '2026-12-01T14:00', '2026-12-08T14:00'], $dates);

        $custom = $this->postJson(self::API.'/appointments', $payload(['assigned_employee' => 'Nora', 'starts_at' => '2027-01-05 14:00:00', 'ends_at' => '2027-01-05 16:00:00', 'occurrences' => 3, 'interval_days' => 14]))->assertCreated();
        $this->assertCount(3, $custom->json('data.appointments'));
    }

    public function test_cancelling_the_rest_of_a_series_keeps_completed_history(): void
    {
        $prospect = $this->prospect();
        $series = $this->postJson(self::API.'/appointments', [
            'prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('accompaniment')->id,
            'starts_at' => '2026-11-17 14:00:00', 'ends_at' => '2026-11-17 16:00:00',
        ])->assertCreated();
        $ids = collect($series->json('data.appointments'))->pluck('id');

        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(17, 0));
        $this->postJson(self::API."/appointments/{$ids[0]}/complete", ['outcome' => 'Went well'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson(self::API."/appointment-series/{$series->json('data.series_id')}/cancel", ['reason' => 'Customer travelling'])
            ->assertOk()->assertJsonPath('data.cancelled', 3);

        $this->assertSame('completed', Appointment::find($ids[0])->status);
        $this->assertSame(3, Appointment::query()->where('status', 'cancelled')->count());
    }

    public function test_future_appointments_are_not_activities_until_completed(): void
    {
        $prospect = $this->prospect();
        $id = $this->postJson(self::API.'/appointments', [
            'prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('meeting')->id,
            'starts_at' => '2026-11-18 10:00:00', 'ends_at' => '2026-11-18 11:00:00',
        ])->assertCreated()->json('data.id');

        $this->postJson(self::API."/appointments/{$id}/complete", ['outcome' => 'x'])->assertStatus(422)->assertJsonPath('error_code', 'APPOINTMENT_NOT_STARTED');
        $this->assertSame(0, Activity::count());

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(12, 0));
        $done = $this->postJson(self::API."/appointments/{$id}/complete", ['outcome' => 'Agreed a quote'])->assertOk();
        $activity = Activity::firstOrFail();
        $this->assertSame($activity->id, $done->json('data.activity_id'));
        $this->assertSame($prospect->id, $activity->prospect_id);
        $this->assertSame('meeting', $activity->type->value);
    }

    public function test_calendar_merges_event_types_filters_and_reports_conflicts(): void
    {
        $prospect = $this->prospect();
        FollowUpTask::factory()->create(['prospect_id' => $prospect->id, 'scheduled_at' => '2026-11-18 15:00:00', 'title' => 'Call back']);
        $this->postJson(self::API.'/appointments', ['prospect_id' => $prospect->id, 'appointment_type_id' => $this->type('meeting')->id, 'starts_at' => '2026-11-18 09:00:00', 'ends_at' => '2026-11-18 10:00:00', 'assigned_employee' => 'Samir']);
        Appointment::query()->create([ // legacy double-booking created before the rules: calendar must flag it
            'appointment_type_id' => $this->type('training')->id, 'prospect_id' => $this->prospect()->id, 'starts_at' => '2026-11-18 09:30:00',
            'ends_at' => '2026-11-18 10:30:00', 'status' => 'confirmed', 'assigned_employee' => 'Samir',
        ]);

        $all = $this->getJson(self::API.'/calendar?from=2026-11-18&to=2026-11-18')->assertOk();
        $this->assertSame(['appointment', 'appointment', 'follow_up'], collect($all->json('data'))->pluck('kind')->all());
        $this->assertSame('same_employee', $all->json('data.0.conflicts.0.reason'));
        $this->assertCount(2, $this->getJson(self::API.'/calendar?from=2026-11-18&to=2026-11-18&employee=Samir')->json('data'));
        $this->assertCount(1, $this->getJson(self::API.'/calendar?from=2026-11-18&to=2026-11-18&kinds[]=follow_up')->json('data'));
    }

    // ---- trials

    public function test_trial_link_is_visible_only_while_active_and_never_after_revocation(): void
    {
        [$prospect, , $headers] = $this->customerWith($this->product);
        $trial = $this->postJson(self::API."/prospects/{$prospect->id}/trials", [
            'product_id' => $this->product->id, 'starts_at' => '2026-11-17 00:00:00', 'ends_at' => '2026-11-20 00:00:00',
        ])->assertCreated();

        $this->getJson(self::PORTAL.'/trials', $headers)->assertJsonPath('data.0.state', 'scheduled')->assertJsonPath('data.0.url', null);

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
        $this->getJson(self::PORTAL.'/trials', $headers)->assertJsonPath('data.0.state', 'active')->assertJsonPath('data.0.url', 'https://trial.example.test/abc');

        $this->postJson(self::API.'/trials/'.$trial->json('data.id').'/revoke')->assertOk()->assertJsonPath('data.state', 'revoked');
        $this->assertStringNotContainsString('trial.example.test', $this->getJson(self::PORTAL.'/trials', $headers)->getContent());
    }

    public function test_expired_trial_hides_its_link_and_trials_do_not_overlap_per_product(): void
    {
        [$prospect, , $headers] = $this->customerWith($this->product);
        $body = ['product_id' => $this->product->id, 'starts_at' => '2026-11-16 00:00:00', 'ends_at' => '2026-11-20 00:00:00'];
        $this->postJson(self::API."/prospects/{$prospect->id}/trials", $body)->assertCreated();
        $this->postJson(self::API."/prospects/{$prospect->id}/trials", $body)->assertStatus(409)->assertJsonPath('error_code', 'TRIAL_OVERLAP');

        $this->travelTo(now()->setDate(2026, 11, 21));
        $json = $this->getJson(self::PORTAL.'/trials', $headers);
        $json->assertJsonPath('data.0.state', 'expired')->assertJsonPath('data.0.url', null);
    }

    public function test_customers_only_see_their_own_trials_appointments_and_resources(): void
    {
        [$mine, , $headers] = $this->customerWith($this->product, 'mine@example.test');
        [$theirs] = $this->customerWith($this->product, 'theirs@example.test');
        $this->postJson(self::API."/prospects/{$theirs->id}/trials", ['product_id' => $this->product->id, 'starts_at' => '2026-11-16 00:00:00', 'ends_at' => '2026-11-30 00:00:00'])->assertCreated();
        $this->postJson(self::API.'/appointments', ['prospect_id' => $theirs->id, 'appointment_type_id' => $this->type('training')->id, 'starts_at' => '2026-11-18 09:00:00', 'ends_at' => '2026-11-18 11:00:00'])->assertCreated();
        $this->postJson(self::API.'/config/product-resources', ['product_id' => $this->product->id, 'title' => 'Guide', 'url' => 'https://drive.example.test/guide'])->assertCreated();
        $this->postJson(self::API.'/config/product-resources', ['product_id' => $this->product->id, 'title' => 'Staff only', 'url' => 'https://drive.example.test/staff', 'customer_visible' => false])->assertCreated();
        $other = Product::factory()->create();
        $this->postJson(self::API.'/config/product-resources', ['product_id' => $other->id, 'title' => 'Not mine', 'url' => 'https://drive.example.test/other'])->assertCreated();

        $this->getJson(self::PORTAL.'/trials', $headers)->assertJsonCount(0, 'data');
        $this->getJson(self::PORTAL.'/appointments', $headers)->assertJsonCount(0, 'data');
        $titles = collect($this->getJson(self::PORTAL.'/resources', $headers)->json('data'))->pluck('title')->all();
        $this->assertSame(['Guide'], $titles);
        $this->assertSame($mine->id, PortalAccount::query()->where('email', 'mine@example.test')->value('prospect_id'));
    }

    public function test_customer_profile_includes_the_prospect_archive(): void
    {
        $prospect = $this->prospect();
        $activity = Activity::factory()->create(['prospect_id' => $prospect->id]);
        $this->postJson(self::API."/prospects/{$prospect->id}/convert", ['product_ids' => []])->assertOk();

        $response = $this->getJson(self::API."/customers/{$prospect->id}")->assertOk()
            ->assertJsonPath('data.prospect.id', $prospect->id)
            ->assertJsonStructure(['data' => ['prospect', 'stage_history', 'activities', 'subscriptions', 'interventions', 'products', 'portal_accounts', 'trials', 'appointments']]);

        $this->assertNotEmpty($response->json('data.stage_history'));
        $this->assertContains($activity->id, collect($response->json('data.activities'))->pluck('id')->all());
    }

    public function test_customer_list_filters_by_maintenance_and_portal_access(): void
    {
        $withPlan = Prospect::factory()->inStage($this->demoStage)->create(['name' => 'Plan Co', 'email' => 'plan@example.test']);
        $noPlan = Prospect::factory()->inStage($this->demoStage)->create(['name' => 'NoPlan Co']);
        foreach ([$withPlan, $noPlan] as $p) {
            $this->postJson(self::API."/prospects/{$p->id}/convert", ['product_ids' => []])->assertOk();
        }
        // Conversion starts a free maintenance period; set known dates for the filters.
        Subscription::query()->where('prospect_id', $withPlan->id)->where('type', Subscription::MAINTENANCE)
            ->update(['status' => 'active', 'expires_at' => now()->addDays(20)]);
        Subscription::query()->where('prospect_id', $noPlan->id)->where('type', Subscription::MAINTENANCE)
            ->update(['status' => 'expired', 'expires_at' => now()->subDays(5)]);

        $ids = fn (string $qs) => collect($this->getJson(self::API.'/customers'.$qs)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$withPlan->id], $ids('?maintenance=active'));
        $this->assertSame([$withPlan->id], $ids('?maintenance=expiring'));
        $this->assertSame([$noPlan->id], $ids('?maintenance=expired_none'));
        $this->assertSame([$withPlan->id], $ids('?search=plan@example'));
        $this->assertEqualsCanonicalizing([$withPlan->id, $noPlan->id], $ids('?portal=no_account'));
        $this->assertSame([], $ids('?portal=has_account'));
        $this->getJson(self::API.'/customers?maintenance=active')->assertJsonPath('data.0.maintenance.status', 'expiring_soon');
    }
}
