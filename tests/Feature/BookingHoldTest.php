<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CrmSetting;
use App\Models\Holiday;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use App\Services\SchedulingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class BookingHoldTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $this->product = Product::factory()->create(['name' => 'GestCom']);
    }

    private function slots(array $headers, string $from = '2026-11-17', string $to = '2026-11-18'): array
    {
        return collect($this->getJson(self::PORTAL."/availability?from={$from}&to={$to}", $headers)->assertOk()->json('data.slots'))
            ->map(fn ($s) => substr($s['starts_at'], 0, 16))->all();
    }

    public function test_availability_follows_configured_working_days_hours_holidays_and_notice(): void
    {
        $slots = $this->slots($this->keyHeaders(), '2026-11-16', '2026-11-22');

        $this->assertContains('2026-11-17T09:00', $slots);
        $this->assertContains('2026-11-17T15:00', $slots);
        $this->assertNotContains('2026-11-17T16:00', $slots);          // slot must end by closing time
        $this->assertNotContains('2026-11-16T09:00', $slots);          // inside the 12h notice window
        $this->assertNotContains('2026-11-20T09:00', $slots);          // Friday is not a working day
        $this->assertNotContains('2026-11-21T09:00', $slots);          // Saturday neither

        Holiday::create(['date' => '2026-11-18', 'name' => 'Holiday']);
        CrmSetting::write('slot_minutes', 120);
        $slots = $this->slots($this->keyHeaders(), '2026-11-17', '2026-11-18');
        $this->assertNotContains('2026-11-18T09:00', $slots);
        $this->assertContains('2026-11-17T11:00', $slots);
        $this->assertNotContains('2026-11-17T10:00', $slots);          // off the 2h grid
    }

    public function test_demo_request_creates_prospect_request_and_an_immediate_hold(): void
    {
        [, $headers] = $this->signedIn();

        $response = $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $response->assertJsonPath('data.status', 'pending')->assertJsonPath('data.appointment.status', 'held');
        $this->assertArrayNotHasKey('internal_notes', $response->json('data.appointment'));

        $prospect = Prospect::query()->where('name', 'Atlas Trading')->firstOrFail();
        $this->assertSame('website', $prospect->source);
        $this->assertSame($this->newStage->id, $prospect->sales_stage_id);
        $this->assertTrue($prospect->products->contains($this->product));
        $this->assertSame(1, $prospect->stageHistories()->count());
        $this->assertSame($prospect->id, PortalAccount::first()->prospect_id);

        $held = Appointment::first();
        $this->assertSame('held', $held->status);
        $this->assertNotNull($held->slot_key);
        $this->assertTrue($held->hold_expires_at->equalTo(now()->addMinutes(1440)));

        $this->assertNotContains('2026-11-17T10:00', $this->slots($headers));   // slot is gone for everyone
    }

    public function test_second_customer_cannot_take_a_held_slot(): void
    {
        [, $first] = $this->signedIn(null, 'one@example.test');
        [, $second] = $this->signedIn(null, 'two@example.test');

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $first)->assertCreated();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $second)
            ->assertStatus(409)->assertJsonPath('error_code', 'SLOT_UNAVAILABLE');

        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, PortalRequest::count());      // the failed attempt rolled back completely
        $this->assertSame(1, Prospect::query()->where('source', 'website')->count());
    }

    public function test_database_unique_key_blocks_double_booking_even_if_application_checks_are_bypassed(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $existing = Appointment::first();

        $this->expectException(UniqueConstraintViolationException::class);
        Appointment::query()->create($existing->only(['appointment_type_id', 'prospect_id']) + [
            'starts_at' => $existing->starts_at, 'ends_at' => $existing->ends_at, 'status' => 'held', 'slot_key' => $existing->slot_key,
        ]);
    }

    public function test_only_one_demo_fits_a_slot(): void
    {
        $tokens = collect(['a', 'b'])->map(fn ($n) => $this->signedIn(null, "{$n}@example.test")[1]);

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $tokens[0])->assertCreated();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $tokens[1])->assertStatus(409);
    }

    public function test_off_grid_past_and_closed_day_times_are_refused(): void
    {
        [, $headers] = $this->signedIn();

        foreach (['2026-11-17 10:30:00', '2026-11-20 10:00:00', '2026-11-16 09:00:00', '2026-12-30 10:00:00'] as $slot) {
            $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, $slot), $headers)
                ->assertStatus(422)->assertJsonPath('error_code', 'SLOT_NOT_OFFERED');
        }
        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Prospect::query()->where('source', 'website')->count());   // a refused slot rolls the new prospect back too
    }

    public function test_hold_expires_releases_the_slot_and_marks_the_request(): void
    {
        CrmSetting::write('hold_expiry_minutes', 60);
        [, $first] = $this->signedIn(null, 'one@example.test');
        [, $second] = $this->signedIn(null, 'two@example.test');
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $first)->assertCreated();

        $this->travel(61)->minutes();

        $this->assertContains('2026-11-17T10:00', $this->slots($second));
        $this->getJson(self::PORTAL.'/requests', $first)->assertJsonPath('data.0.status', 'expired');
        $this->assertSame('expired', Appointment::first()->status);
        $this->assertNull(Appointment::first()->slot_key);

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $second)->assertCreated();
    }

    public function test_scheduler_command_releases_expired_holds(): void
    {
        CrmSetting::write('hold_expiry_minutes', 30);
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();

        $this->travel(31)->minutes();
        $this->artisan('crm:release-expired-holds')->expectsOutputToContain('Released 1')->assertSuccessful();
        $this->assertSame('expired', Appointment::first()->status);
    }

    public function test_employee_confirms_with_meeting_link_and_customer_sees_it_only_then(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $id = Appointment::first()->id;

        $this->getJson(self::PORTAL.'/appointments', $headers)->assertJsonPath('data.0.meeting_url', null);

        $this->postJson(self::API."/appointments/{$id}/confirm", ['meeting_url' => 'https://meet.example.test/abc', 'assigned_employee' => 'Samir'], ['X-Employee-Name' => 'Samir'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.assigned_employee', 'Samir');

        $this->getJson(self::PORTAL.'/appointments', $headers)->assertJsonPath('data.0.status', 'confirmed')->assertJsonPath('data.0.meeting_url', 'https://meet.example.test/abc');
        $this->getJson(self::PORTAL.'/requests', $headers)->assertJsonPath('data.0.status', 'scheduled');

        $this->postJson(self::API."/appointments/{$id}/confirm", [])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_APPOINTMENT_STATE');
        $this->assertNotNull(Appointment::first()->slot_key);   // a confirmed demo keeps blocking the slot
    }

    public function test_expired_hold_cannot_be_confirmed(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $id = Appointment::first()->id;

        $this->travel(25)->hours();
        $this->postJson(self::API."/appointments/{$id}/confirm", [])->assertStatus(410)->assertJsonPath('error_code', 'HOLD_EXPIRED');
    }

    public function test_rejection_releases_the_slot_with_a_customer_safe_message_and_internal_notes_stay_private(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $appointment = Appointment::first();
        $appointment->update(['internal_notes' => 'SECRET staff note']);

        $this->postJson(self::API."/appointments/{$appointment->id}/reject", ['reason' => 'We are fully booked that day.'])->assertOk();

        $this->assertNull($appointment->fresh()->slot_key);
        $this->assertContains('2026-11-17T10:00', $this->slots($headers));
        $json = $this->getJson(self::PORTAL.'/requests', $headers)->assertJsonPath('data.0.status', 'rejected')->getContent();
        $this->assertStringContainsString('fully booked', $json);
        $this->assertStringNotContainsString('SECRET', $json);
    }

    public function test_employee_proposes_another_time_and_customer_accepts_or_declines(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $id = Appointment::first()->id;

        $this->postJson(self::API."/appointments/{$id}/propose", ['starts_at' => '2026-11-18 11:00:00', 'message' => 'Would 11:00 work?'])
            ->assertOk()->assertJsonPath('data.status', 'proposed');
        $this->assertContains('2026-11-17T10:00', $this->slots($headers));        // old slot released
        $this->assertNotContains('2026-11-18T11:00', $this->slots($headers));     // new one held for the customer

        $this->postJson(self::PORTAL."/appointments/{$id}/accept", [], $headers)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('scheduled', PortalRequest::first()->status);
    }

    public function test_customer_cancellation_and_duplicate_request_protection(): void
    {
        [, $headers] = $this->signedIn();
        $first = $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated()->json('data.id');

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, '2026-11-17 11:00:00'), $headers)
            ->assertStatus(409)->assertJsonPath('error_code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Prospect::query()->where('source', 'website')->count());

        $this->postJson(self::PORTAL."/requests/{$first}/cancel", [], $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertContains('2026-11-17T10:00', $this->slots($headers));
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, '2026-11-17 11:00:00'), $headers)->assertCreated();
        $this->assertSame(1, Prospect::query()->where('source', 'website')->count());   // same account => same prospect
    }

    public function test_validation_and_unavailable_products(): void
    {
        [, $headers] = $this->signedIn();
        $this->postJson(self::PORTAL.'/requests', [], $headers)->assertStatus(422)->assertJsonValidationErrors(['type', 'product_id', 'company', 'phone', 'wilaya', 'city'], 'errors');

        $inactive = Product::factory()->inactive()->create();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($inactive), $headers)->assertStatus(422)->assertJsonPath('error_code', 'PRODUCT_UNAVAILABLE');
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null), $headers)->assertStatus(422)->assertJsonPath('error_code', 'SLOT_REQUIRED');
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null, 'quotation'), $headers)->assertCreated()->assertJsonPath('data.appointment', null);
    }

    public function test_unverified_email_never_links_to_an_existing_prospect_but_flags_a_possible_duplicate(): void
    {
        $existing = Prospect::factory()->inStage($this->newStage)->create(['email' => 'karim@atlas.test', 'notes' => 'Private CRM notes']);
        [, $headers] = $this->signedIn();

        $response = $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();

        $created = Prospect::query()->where('source', 'website')->firstOrFail();
        $this->assertNotSame($existing->id, $created->id);
        $this->assertStringContainsString("Possible duplicate of prospect #{$existing->id}", $created->notes);
        $this->assertStringNotContainsString('Private CRM notes', $response->getContent());
    }

    public function test_scheduling_settings_are_configurable_through_the_staff_api(): void
    {
        $this->putJson(self::API.'/settings/scheduling', ['slot_minutes' => 30, 'work_start' => '08:00', 'work_end' => '12:00', 'working_days' => [1, 2]])
            ->assertOk()->assertJsonPath('data.slot_minutes', 30);
        $this->putJson(self::API.'/settings/scheduling', ['work_start' => '10:00', 'work_end' => '09:00'])->assertStatus(422);

        $this->assertSame(30, app(SchedulingService::class)->setting('slot_minutes'));
        $this->getJson(self::API.'/lookups')->assertOk()->assertJsonPath('data.scheduling.slot_minutes', 30)->assertJsonStructure(['data' => ['appointment_types', 'intervention_statuses', 'intervention_priorities']]);
    }
}
