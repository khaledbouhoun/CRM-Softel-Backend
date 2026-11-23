<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductRelease;
use App\Models\Prospect;
use App\Models\RenewalRequest;
use App\Models\Subscription;
use App\Services\BillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $erp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal(); // clock frozen at 2026-11-16 08:00
        Storage::fake('local');
        $this->erp = Product::factory()->create(['name' => 'ERP']);
    }

    private function maintenance(Prospect $p, string $starts, string $expires, string $status = 'active'): Subscription
    {
        return Subscription::query()->create(['prospect_id' => $p->id, 'type' => 'maintenance', 'status' => $status, 'starts_at' => $starts, 'expires_at' => $expires]);
    }

    private function release(string $version, string $date): ProductRelease
    {
        return ProductRelease::query()->create([
            'product_id' => $this->erp->id, 'version_number' => $version, 'release_date' => $date, 'audience' => 'all', 'is_published' => true,
            'installer_path' => 'product-releases/'.$version.'.exe', 'installer_name' => $version.'.exe', 'installer_size' => 10,
        ]);
    }

    // ---- customer portal

    public function test_customer_sees_lifetime_license_and_no_plan_by_default(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);

        $this->getJson(self::PORTAL.'/billing', $h)->assertOk()
            ->assertJsonPath('data.license.lifetime', true)
            ->assertJsonPath('data.subscription.status', 'none')
            ->assertJsonPath('data.subscription.is_active', false)
            ->assertJsonPath('data.invoices', []);

        $this->assertDatabaseHas('subscriptions', ['prospect_id' => $prospect->id, 'type' => 'lifetime_license', 'expires_at' => null]);
    }

    public function test_accounts_that_are_not_customers_cannot_use_billing(): void
    {
        [, $h] = $this->signedIn(null, 'nobody@example.test');

        $this->getJson(self::PORTAL.'/billing', $h)->assertStatus(403)->assertJsonPath('error_code', 'CUSTOMER_REQUIRED');
        $this->postJson(self::PORTAL.'/billing/renew', self::REQ, $h)->assertStatus(403);
    }

    public function test_billing_requires_the_portal_token(): void
    {
        $this->getJson(self::PORTAL.'/billing', $this->keyHeaders())->assertStatus(401);
    }

    private const REQ = ['years' => 1, 'workstations_count' => 3, 'dossiers_count' => 2, 'notes' => 'Two sites'];

    /** Customer asks for a quote, staff price it: returns the renewal request id. */
    private function quotedRenewal(array $h, int $amount = 48000, array $request = self::REQ): int
    {
        $id = $this->postJson(self::PORTAL.'/billing/renew', $request, $h)->assertCreated()->json('data.id');
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => $amount], ['X-Employee-Name' => 'Samir'])->assertOk();

        return $id;
    }

    public function test_free_maintenance_ends_on_december_31st_with_the_april_first_cutoff(): void
    {
        $svc = app(BillingService::class);

        $this->assertSame('2026-12-31', $svc->calculateFirstFreeExpiry(Carbon::parse('2026-02-15'))->toDateString());
        $this->assertSame('2026-12-31', $svc->calculateFirstFreeExpiry(Carbon::parse('2026-03-31 23:59:00'))->toDateString());
        $this->assertSame('2027-12-31', $svc->calculateFirstFreeExpiry(Carbon::parse('2026-04-01'))->toDateString());
        $this->assertSame('2027-12-31', $svc->calculateFirstFreeExpiry(Carbon::parse('2026-07-05'))->toDateString());
    }

    public function test_converting_a_prospect_starts_the_free_maintenance_period(): void
    {
        $prospect = Prospect::factory()->inStage($this->wonStage)->create();
        $prospect->products()->attach($this->erp->id);

        $this->postJson(self::API."/prospects/{$prospect->id}/convert", ['product_ids' => [$this->erp->id]])->assertOk();

        // Frozen clock: 2026-11-16 is after April 1st, so the free period runs to the end of next year.
        $sub = Subscription::where('prospect_id', $prospect->id)->where('type', 'maintenance')->first();
        $this->assertSame('2027-12-31', $sub->expires_at->toDateString());
        $this->assertTrue($sub->isActive());
    }

    public function test_renewal_request_waits_for_a_quote_and_creates_no_invoice_yet(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);

        $this->postJson(self::PORTAL.'/billing/renew', ['years' => 2, 'workstations_count' => 3, 'dossiers_count' => 2, 'notes' => 'Two sites'], $h)->assertCreated()
            ->assertJsonPath('data.status', 'pending_quote')
            ->assertJsonPath('data.years', 2)
            ->assertJsonPath('data.workstations_count', 3)
            ->assertJsonPath('data.dossiers_count', 2)
            ->assertJsonPath('data.notes', 'Two sites')
            ->assertJsonPath('data.amount_dzd', null)
            ->assertJsonPath('data.reference', null);

        $this->assertSame(0, Invoice::count());
        $this->assertNull(Subscription::query()->where('type', 'maintenance')->first());

        // Asking again updates the open request instead of piling up duplicates.
        $this->postJson(self::PORTAL.'/billing/renew', self::REQ, $h)->assertCreated()->assertJsonPath('data.years', 1)->assertJsonPath('data.workstations_count', 3);
        $this->assertSame(1, RenewalRequest::count());
        $this->getJson(self::PORTAL.'/billing', $h)->assertJsonPath('data.pending_renewal.status', 'pending_quote')->assertJsonMissingPath('data.renewal_price_per_year');
    }

    public function test_staff_quote_issues_the_proforma_and_the_pending_cash_invoice(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $id = $this->postJson(self::PORTAL.'/billing/renew', self::REQ, $h)->json('data.id');

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => 48000], ['X-Employee-Name' => 'Samir'])->assertOk()
            ->assertJsonPath('data.renewal_requests.0.status', 'quoted')
            ->assertJsonPath('data.renewal_requests.0.reference', 'PF-2026-0001')
            ->assertJsonPath('data.renewal_requests.0.amount_dzd', 48000)
            ->assertJsonPath('data.renewal_requests.0.quoted_by', 'Samir')
            ->assertJsonPath('data.invoices.0.status', 'pending_cash');

        $this->assertDatabaseHas('invoices', ['prospect_id' => $prospect->id, 'type' => 'maintenance_renewal', 'status' => 'pending_cash', 'amount_dzd' => 48000, 'reference' => 'SOF-2026-0001']);
        $this->getJson(self::PORTAL.'/billing', $h)->assertJsonPath('data.pending_renewal.status', 'quoted')->assertJsonPath('data.pending_renewal.amount_dzd', 48000);

        // Re-quoting changes the amount on the same proforma and invoice.
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => 50000])->assertOk();
        $this->assertSame(1, Invoice::count());
        $this->assertSame(50000, Invoice::first()->amount_dzd);

        // A new request from the customer sends it back to pricing and drops the old proforma invoice.
        $this->postJson(self::PORTAL.'/billing/renew', ['years' => 2, 'workstations_count' => 5, 'dossiers_count' => 2], $h)->assertCreated()->assertJsonPath('data.status', 'pending_quote');
        $this->assertSame(0, Invoice::count());
    }

    public function test_quote_amount_is_validated_and_a_paid_request_cannot_be_requoted(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $id = $this->postJson(self::PORTAL.'/billing/renew', self::REQ, $h)->json('data.id');

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => 0])->assertStatus(422)->assertJsonValidationErrors('amount_dzd');
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', [])->assertStatus(422);

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => 1000])->assertOk();
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', [])->assertOk();
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/quote', ['amount_dzd' => 2000])->assertStatus(422);
    }

    public function test_renewal_validates_the_request_fields(): void
    {
        [, , $h] = $this->customerWith($this->erp);

        $this->postJson(self::PORTAL.'/billing/renew', ['years' => 0, 'workstations_count' => 1, 'dossiers_count' => 1], $h)->assertStatus(422)->assertJsonValidationErrors('years');
        $this->postJson(self::PORTAL.'/billing/renew', ['years' => 1, 'dossiers_count' => 1], $h)->assertStatus(422)->assertJsonValidationErrors('workstations_count');
        $this->postJson(self::PORTAL.'/billing/renew', ['years' => 1, 'workstations_count' => 1, 'dossiers_count' => 0], $h)->assertStatus(422)->assertJsonValidationErrors('dossiers_count');
        $this->postJson(self::PORTAL.'/billing/renew', [], $h)->assertStatus(422);
    }

    public function test_customers_only_see_their_own_billing(): void
    {
        [$a, , $ha] = $this->customerWith($this->erp, 'a@example.test');
        [, , $hb] = $this->customerWith($this->erp, 'b@example.test');
        $this->quotedRenewal($ha);

        $this->getJson(self::PORTAL.'/billing', $hb)->assertJsonCount(0, 'data.invoices')->assertJsonPath('data.pending_renewal', null);
        $invoice = Invoice::where('prospect_id', $a->id)->first();
        $this->getJson(self::PORTAL.'/billing/invoices/'.$invoice->id, $hb)->assertStatus(404);
    }

    public function test_invoice_pdf_is_404_until_staff_upload_it_then_streams(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $this->quotedRenewal($h);
        $invoice = Invoice::first();

        $this->get(self::PORTAL.'/billing/invoices/'.$invoice->id, $h)->assertStatus(404);
        $this->getJson(self::PORTAL.'/billing', $h)->assertJsonPath('data.invoices.0.has_pdf', false);

        $this->post(self::API.'/billing/invoices/'.$invoice->id.'/receipt', ['receipt' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_pdf', true)->assertJsonPath('data.pdf_name', 'scan.pdf');
        Storage::disk('local')->assertExists($invoice->refresh()->pdf_path);

        $this->get(self::PORTAL.'/billing/invoices/'.$invoice->id, $h)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson(self::PORTAL.'/billing', $h)->assertJsonPath('data.invoices.0.has_pdf', true);

        $this->delete(self::API.'/billing/invoices/'.$invoice->id.'/receipt', [], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_pdf', false);
        $this->get(self::PORTAL.'/billing/invoices/'.$invoice->id, $h)->assertStatus(404);
    }

    public function test_receipt_upload_rejects_other_file_types(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $this->quotedRenewal($h);

        $this->post(self::API.'/billing/invoices/'.Invoice::first()->id.'/receipt', ['receipt' => UploadedFile::fake()->create('x.php', 1)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('receipt');
    }

    // ---- staff: confirming cash

    public function test_cash_cannot_be_confirmed_before_a_quote(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $id = $this->postJson(self::PORTAL.'/billing/renew', self::REQ, $h)->json('data.id');

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', [])->assertStatus(422);
        $this->assertNull(Subscription::query()->where('type', 'maintenance')->first());
    }

    public function test_paying_an_expired_plan_covers_the_current_year_onwards_to_a_december_31st(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $this->maintenance($prospect, '2025-01-10 00:00:00', '2025-12-31 23:59:59', 'expired');
        $id = $this->quotedRenewal($h, 72000, ['years' => 2, 'workstations_count' => 3, 'dossiers_count' => 2]);

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', ['paid_at' => '2026-11-16'], ['X-Employee-Name' => 'Samir'])->assertOk()
            ->assertJsonPath('data.maintenance.status', 'active')
            ->assertJsonPath('data.renewal_requests.0.status', 'paid_cash')
            ->assertJsonPath('data.renewal_requests.0.confirmed_by', 'Samir')
            ->assertJsonPath('data.invoices.0.status', 'paid_cash');

        // Lapsed plan: 2 years starting with the current calendar year (2026) end on 31-12-2027.
        $sub = Subscription::where('prospect_id', $prospect->id)->where('type', 'maintenance')->first();
        $this->assertSame('2027-12-31', $sub->expires_at->toDateString());
        $this->assertSame('active', $sub->status);
        $this->assertNotNull(Invoice::first()->paid_at);

        $this->getJson(self::PORTAL.'/billing', $h)->assertJsonPath('data.subscription.is_active', true)->assertJsonPath('data.pending_renewal', null);
    }

    public function test_renewing_an_active_plan_adds_whole_calendar_years_after_its_current_expiry(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $this->maintenance($prospect, '2026-01-10 10:00:00', '2026-12-31 23:59:59');
        $id = $this->quotedRenewal($h);

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', [])->assertOk();

        $this->assertSame('2027-12-31', Subscription::where('type', 'maintenance')->first()->expires_at->toDateString());
    }

    public function test_a_renewal_can_only_be_confirmed_once(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $id = $this->quotedRenewal($h);

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', [])->assertOk();
        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', [])->assertStatus(422);

        $this->assertSame(1, Subscription::where('type', 'maintenance')->count());
    }

    public function test_paying_the_renewal_invoice_confirms_the_request_too(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $this->quotedRenewal($h);

        $this->postJson(self::API.'/billing/invoices/'.Invoice::first()->id.'/mark-paid', ['paid_at' => '2026-11-15'])->assertOk()->assertJsonPath('data.status', 'paid_cash');

        $this->assertSame('paid_cash', RenewalRequest::first()->status);
        $this->assertSame('2026-12-31', Subscription::where('prospect_id', $prospect->id)->where('type', 'maintenance')->first()->expires_at->toDateString());
    }

    public function test_paid_at_cannot_be_in_the_future(): void
    {
        [, , $h] = $this->customerWith($this->erp);
        $id = $this->quotedRenewal($h);

        $this->postJson(self::API.'/billing/renewal-requests/'.$id.'/mark-paid', ['paid_at' => '2026-12-30'])->assertStatus(422)->assertJsonValidationErrors('paid_at');
    }

    public function test_staff_can_set_the_maintenance_period_and_create_license_invoices(): void
    {
        $prospect = $this->customerWith($this->erp)[0];

        $this->putJson(self::API.'/billing/customers/'.$prospect->id.'/maintenance', ['starts_at' => '2026-11-01', 'expires_at' => '2027-11-01'])->assertOk()
            ->assertJsonPath('data.maintenance.status', 'active');
        $this->putJson(self::API.'/billing/customers/'.$prospect->id.'/maintenance', ['starts_at' => '2026-11-01', 'expires_at' => '2026-10-01'])->assertStatus(422);

        $this->postJson(self::API.'/billing/customers/'.$prospect->id.'/invoices', ['type' => 'license', 'amount_dzd' => 180000, 'status' => 'paid_cash', 'paid_at' => '2026-11-10'], ['X-Employee-Name' => 'Samir'])
            ->assertCreated()->assertJsonPath('data.reference', 'SOF-2026-0001')->assertJsonPath('data.status', 'paid_cash')->assertJsonPath('data.created_by', 'Samir');
        $this->postJson(self::API.'/billing/customers/'.$prospect->id.'/invoices', ['type' => 'license', 'amount_dzd' => 1000])->assertCreated()->assertJsonPath('data.reference', 'SOF-2026-0002')->assertJsonPath('data.status', 'pending_cash');
        $this->postJson(self::API.'/billing/customers/'.$prospect->id.'/invoices', ['type' => 'gift', 'amount_dzd' => 1])->assertStatus(422);
    }

    public function test_staff_billing_pages_404_for_prospects_that_are_not_customers(): void
    {
        $prospect = Prospect::factory()->inStage($this->newStage)->create();

        $this->getJson(self::API.'/billing/customers/'.$prospect->id)->assertStatus(404);
    }

    public function test_staff_list_filters_and_sorts_by_maintenance_status(): void
    {
        $make = fn () => $this->customerWith($this->erp, uniqid().'@example.test')[0];
        [$active, $soon, $expired, $none] = [$make(), $make(), $make(), $make()];
        $this->maintenance($active, '2026-01-01', '2027-03-01');
        $this->maintenance($soon, '2026-01-01', '2026-11-26');
        $this->maintenance($expired, '2025-01-01', '2026-11-01', 'expired');
        RenewalRequest::query()->create(['prospect_id' => $soon->id, 'years' => 1, 'amount_dzd' => 36000, 'status' => 'quoted', 'reference' => 'PF-2026-0099']);

        $ids = fn (string $query) => collect($this->getJson(self::API.'/billing/subscriptions'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$active->id], $ids('?status=active'));
        $this->assertSame([$soon->id], $ids('?status=expiring_soon'));
        $this->assertSame([$expired->id], $ids('?status=expired'));
        $this->assertSame([$none->id], $ids('?status=none'));
        $this->assertSame([$expired->id, $soon->id, $active->id, $none->id], $ids('?sort=expires_at&direction=asc'));
        $this->assertSame([$soon->id], $ids('?has_pending_renewal=1'));

        $row = collect($this->getJson(self::API.'/billing/subscriptions')->json('data'))->firstWhere('id', $soon->id);
        $this->assertSame('expiring_soon', $row['maintenance']['status']);
        $this->assertSame(1, $row['pending_renewals_count']);
        $this->assertSame(10, $row['maintenance']['days_remaining']);
    }

    // ---- release gating

    public function test_expired_maintenance_locks_only_versions_released_after_it_expired(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $this->maintenance($prospect, '2025-10-01', '2026-10-01', 'expired');
        $old = $this->release('4.0.0', '2026-09-15');
        $new = $this->release('5.0.0', '2026-11-10');
        Storage::disk('local')->put($old->installer_path, 'x');
        Storage::disk('local')->put($new->installer_path, 'x');

        $res = $this->getJson(self::PORTAL.'/releases', $h)->assertOk()->assertJsonPath('meta.maintenance_active', false);
        $byVersion = collect($res->json('data'))->keyBy('version_number');
        $this->assertFalse($byVersion['4.0.0']['maintenance_required']);
        $this->assertNotNull($byVersion['4.0.0']['installer']);
        $this->assertTrue($byVersion['5.0.0']['maintenance_required']);
        $this->assertNull($byVersion['5.0.0']['installer']);

        $this->get(self::PORTAL.'/releases/'.$old->id.'/download', $h)->assertOk();
        $this->getJson(self::PORTAL.'/releases/'.$new->id.'/download', $h)->assertStatus(403)->assertJsonPath('error_code', 'MAINTENANCE_REQUIRED');
    }

    public function test_active_maintenance_or_no_plan_allows_every_version(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $new = $this->release('5.0.0', '2026-11-10');
        Storage::disk('local')->put($new->installer_path, 'x');

        // No maintenance plan set up yet: nothing is restricted.
        $this->getJson(self::PORTAL.'/releases', $h)->assertJsonPath('meta.maintenance_active', true)->assertJsonPath('data.0.maintenance_required', false);

        $this->maintenance($prospect, '2026-01-01', '2027-01-01');
        $this->getJson(self::PORTAL.'/releases', $h)->assertJsonPath('meta.maintenance_active', true)->assertJsonPath('data.0.maintenance_required', false);
        $this->get(self::PORTAL.'/releases/'.$new->id.'/download', $h)->assertOk();
    }

    // ---- overview + housekeeping

    public function test_overview_reports_maintenance_and_counts(): void
    {
        [$prospect, , $h] = $this->customerWith($this->erp);
        $this->maintenance($prospect, '2026-01-01', '2027-01-01');

        $this->getJson(self::PORTAL.'/overview', $h)->assertOk()
            ->assertJsonPath('data.maintenance.status', 'active')
            ->assertJsonPath('data.maintenance.is_active', true)
            ->assertJsonPath('data.open_interventions_count', 0)
            ->assertJsonPath('data.awaiting_customer_count', 0)
            ->assertJsonPath('data.upcoming_appointments_count', 0);

        [, $hGuest] = $this->signedIn(null, 'guest@example.test');
        $this->getJson(self::PORTAL.'/overview', $hGuest)->assertOk()->assertJsonPath('data.maintenance', null);
    }

    public function test_sync_command_flags_lapsed_plans(): void
    {
        [$prospect] = $this->customerWith($this->erp);
        $sub = $this->maintenance($prospect, '2025-01-01', '2026-11-01');

        $this->artisan('billing:sync-subscriptions')->assertSuccessful();

        $this->assertSame('expired', $sub->refresh()->status);
    }
}
