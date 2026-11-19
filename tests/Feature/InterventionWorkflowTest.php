<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\InterventionAttachment;
use App\Models\InterventionPriority;
use App\Models\InterventionStatus;
use App\Models\InterventionType;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class InterventionWorkflowTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        Storage::fake('local');
        Storage::fake('public');
        $this->product = Product::factory()->create(['name' => 'GestCom']);
    }

    private function type(string $code): int
    {
        return InterventionType::where('code', $code)->value('id');
    }

    private function st(string $code): int
    {
        return InterventionStatus::where('code', $code)->value('id');
    }

    private function report(array $headers, array $over = []): array
    {
        return $this->postJson(self::PORTAL.'/interventions', $over + [
            'intervention_type_id' => $this->type('bug'), 'product_id' => $this->product->id,
            'title' => 'Invoice total wrong', 'description' => 'Totals ignore the discount.',
        ], $headers)->assertCreated()->json('data');
    }

    public function test_customer_reports_an_issue_for_an_owned_product_and_starts_as_new(): void
    {
        [, , $headers] = $this->customerWith($this->product);

        $data = $this->report($headers);

        $this->assertSame('Received', $data['status']['label']);
        $this->assertSame('customer', $data['source']);
        $this->assertArrayNotHasKey('priority', $data);
        $this->assertArrayNotHasKey('assigned_employee', $data);
        $this->assertSame('new', Intervention::first()->status->code);
        $this->assertStringStartsWith('INT-', $data['reference']);
    }

    public function test_customer_cannot_report_on_a_product_they_do_not_own_or_with_a_staff_only_type(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $other = Product::factory()->create();

        $this->postJson(self::PORTAL.'/interventions', ['intervention_type_id' => $this->type('bug'), 'product_id' => $other->id, 'title' => 'x', 'description' => 'y'], $headers)
            ->assertStatus(403)->assertJsonPath('error_code', 'PRODUCT_NOT_OWNED');
        $this->postJson(self::PORTAL.'/interventions', ['intervention_type_id' => $this->type('fix'), 'product_id' => $this->product->id, 'title' => 'x', 'description' => 'y'], $headers)
            ->assertStatus(422)->assertJsonPath('error_code', 'TYPE_UNAVAILABLE');

        [, $prospectless] = $this->signedIn(null, 'new@example.test');
        $this->postJson(self::PORTAL.'/interventions', ['intervention_type_id' => $this->type('bug'), 'product_id' => $this->product->id, 'title' => 'x', 'description' => 'y'], $prospectless)
            ->assertStatus(403)->assertJsonPath('error_code', 'NO_CUSTOMER');
    }

    public function test_customers_never_see_other_customers_requests(): void
    {
        [, , $mine] = $this->customerWith($this->product, 'mine@example.test');
        [, , $theirs] = $this->customerWith($this->product, 'theirs@example.test');
        $id = $this->report($theirs)['id'];

        $this->getJson(self::PORTAL.'/interventions', $mine)->assertJsonCount(0, 'data');
        $this->getJson(self::PORTAL."/interventions/{$id}", $mine)->assertStatus(404);
        $this->postJson(self::PORTAL."/interventions/{$id}/messages", ['body' => 'hi'], $mine)->assertStatus(404);
        $this->postJson(self::PORTAL."/interventions/{$id}/transition", ['status_id' => $this->st('reopened')], $mine)->assertStatus(404);
        $this->postJson(self::PORTAL."/interventions/{$id}/attachments", ['file' => UploadedFile::fake()->create('a.png', 10, 'image/png')], $mine)->assertStatus(404);
        $this->getJson(self::PORTAL."/interventions/{$id}/attachments/1", $mine)->assertStatus(404);
    }

    public function test_internal_notes_priority_and_assignee_never_reach_the_customer(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $id = $this->report($headers)['id'];

        $this->patchJson(self::API."/interventions/{$id}", ['intervention_priority_id' => InterventionPriority::where('code', 'p1')->value('id'), 'assigned_employee' => 'DevSecretName'])->assertOk();
        $this->postJson(self::API."/interventions/{$id}/messages", ['visibility' => 'internal', 'body' => 'INTERNAL: probably the rounding bug'])->assertCreated();
        $this->postJson(self::API."/interventions/{$id}/messages", ['visibility' => 'customer', 'body' => 'We are looking into it.'])->assertCreated();
        $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $this->st('under_review'), 'customer_message' => 'Under review now'])->assertOk();

        $json = $this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertOk()->getContent();
        $this->assertStringContainsString('We are looking into it.', $json);
        $this->assertStringContainsString('Under review', $json);
        foreach (['INTERNAL', 'rounding', 'DevSecretName', 'p1', 'P1', 'priority', 'assigned', 'internal_notes'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString('INTERNAL', $this->getJson(self::PORTAL.'/interventions', $headers)->getContent());

        $staff = $this->getJson(self::API."/interventions/{$id}")->assertOk();
        $this->assertCount(3, collect($staff->json('data.messages'))->where('visibility', '!=', 'x')->all() ?: []);
    }

    public function test_only_configured_transitions_work_and_customers_only_use_customer_allowed_ones(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $id = $this->report($headers)['id'];

        $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $this->st('delivered')])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_STATUS_TRANSITION');
        $this->postJson(self::PORTAL."/interventions/{$id}/transition", ['status_id' => $this->st('under_review')], $headers)->assertStatus(422);

        foreach (['under_review', 'queued', 'in_progress', 'testing', 'delivered'] as $code) {
            $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $this->st($code)])->assertOk();
        }
        $this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertJsonPath('data.status.label', 'Delivered');

        $options = $this->getJson(self::PORTAL."/interventions/{$id}/transitions", $headers)->json('data');
        $this->assertSame([$this->st('reopened')], array_column($options, 'id'));

        $this->postJson(self::PORTAL."/interventions/{$id}/transition", ['status_id' => $this->st('reopened'), 'message' => 'Still broken'], $headers)
            ->assertOk()->assertJsonPath('data.status.label', 'Reopened');
        $this->assertNull(Intervention::first()->closed_at);
    }

    public function test_status_workflow_is_configurable_and_deactivation_keeps_history(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $id = $this->report($headers)['id'];

        $newStatus = $this->postJson(self::API.'/config/intervention-statuses', ['code' => 'vendor', 'name' => 'Waiting for vendor', 'customer_label' => 'With our supplier', 'color' => '#112233'])->assertCreated()->json('data.id');
        $this->postJson(self::API.'/config/intervention-transitions', ['from_status_id' => $this->st('new'), 'to_status_id' => $newStatus])->assertCreated();
        $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $newStatus])->assertOk();
        $this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertJsonPath('data.status.label', 'With our supplier');

        $this->deleteJson(self::API."/config/intervention-statuses/{$newStatus}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('intervention_statuses', ['id' => $newStatus, 'is_active' => false]);
        $this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertJsonPath('data.status.label', 'With our supplier');   // history still readable

        $this->postJson(self::API.'/config/intervention-priorities', ['code' => 'p0', 'name' => 'P0 Blocker', 'color' => '#000000', 'response_target_hours' => 1])->assertCreated();
        $this->putJson(self::API.'/config/intervention-priorities/'.InterventionPriority::where('code', 'p3')->value('id'), ['name' => 'P3 Medium', 'color' => '#abcdef'])->assertOk()->assertJsonPath('data.name', 'P3 Medium');
        $this->postJson(self::API.'/config/intervention-priorities', ['code' => 'Bad Code', 'name' => 'x'])->assertStatus(422);
    }

    public function test_customer_reply_to_an_information_request_moves_the_request_on(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $id = $this->report($headers)['id'];
        $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $this->st('under_review')])->assertOk();
        $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $this->st('waiting_customer'), 'customer_message' => 'Which version do you use?'])->assertOk();
        $this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertJsonPath('data.status.awaits_customer', true);

        $this->postJson(self::PORTAL."/interventions/{$id}/messages", ['body' => 'Version 4.2'], $headers)->assertOk()
            ->assertJsonPath('data.status.label', 'Under review')->assertJsonPath('data.status.awaits_customer', false);
    }

    public function test_attachments_are_validated_stored_privately_and_downloadable_only_by_the_owner(): void
    {
        [, , $headers] = $this->customerWith($this->product, 'mine@example.test');
        [, , $other] = $this->customerWith($this->product, 'other@example.test');
        $id = $this->report($headers)['id'];

        $this->postJson(self::PORTAL."/interventions/{$id}/attachments", ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], $headers)->assertStatus(422);
        $this->postJson(self::PORTAL."/interventions/{$id}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], $headers)->assertStatus(422);
        $up = $this->postJson(self::PORTAL."/interventions/{$id}/attachments", ['file' => UploadedFile::fake()->create('shot.png', 10, 'image/png')], $headers)->assertCreated();

        $attachment = $up->json('data.attachments.0');
        $this->assertStringNotContainsString('path', json_encode($attachment));
        Storage::disk('local')->assertExists(InterventionAttachment::first()->path);
        Storage::disk('public')->assertDirectoryEmpty('/');

        $this->get(self::PORTAL."/interventions/{$id}/attachments/{$attachment['id']}", $headers)->assertOk();
        $this->get(self::PORTAL."/interventions/{$id}/attachments/{$attachment['id']}", $other)->assertStatus(404);

        InterventionAttachment::first()->update(['visibility' => 'internal']);
        $this->get(self::PORTAL."/interventions/{$id}/attachments/{$attachment['id']}", $headers)->assertStatus(404);
    }

    public function test_employee_published_updates_reach_only_affected_owners_of_the_product(): void
    {
        [$mine, , $mineHeaders] = $this->customerWith($this->product, 'mine@example.test');
        [, , $otherProductHeaders] = $this->customerWith(Product::factory()->create(), 'other@example.test');

        $id = $this->postJson(self::API.'/interventions', [
            'intervention_type_id' => $this->type('fix'), 'product_id' => $this->product->id, 'title' => 'Fix: rounding of totals',
            'description' => 'INTERNAL dev description with stack details', 'assigned_employee' => 'Dev1',
        ])->assertCreated()->json('data.id');

        $this->getJson(self::PORTAL.'/updates', $mineHeaders)->assertJsonCount(0, 'data');   // draft is invisible

        $this->postJson(self::API."/interventions/{$id}/publish", ['customer_resolution' => 'Totals now round correctly (v4.2.1).'])->assertOk();

        $feed = $this->getJson(self::PORTAL.'/updates', $mineHeaders)->assertJsonCount(1, 'data');
        $this->assertStringContainsString('v4.2.1', $feed->getContent());
        foreach (['INTERNAL', 'Dev1', 'stack details'] as $secret) {
            $this->assertStringNotContainsString($secret, $feed->getContent());
        }
        $this->getJson(self::PORTAL.'/updates', $otherProductHeaders)->assertJsonCount(0, 'data');

        // Targeting named customers narrows the audience to them only.
        $this->postJson(self::API.'/interventions', [
            'intervention_type_id' => $this->type('service'), 'product_id' => $this->product->id, 'title' => 'Hotfix for one site',
            'description' => 'x', 'customer_ids' => [$mine->id],
        ])->assertCreated();
        $second = Intervention::query()->where('title', 'Hotfix for one site')->first();
        $this->postJson(self::API."/interventions/{$second->id}/publish", ['customer_resolution' => 'Patched.'])->assertOk();
        $this->getJson(self::PORTAL.'/updates', $mineHeaders)->assertJsonCount(2, 'data');

        $this->postJson(self::API."/interventions/{$id}/unpublish")->assertOk();
        $this->getJson(self::PORTAL.'/updates', $mineHeaders)->assertJsonCount(1, 'data');
        $this->postJson(self::API."/interventions/{$id}/publish", [])->assertStatus(422);
    }

    public function test_staff_triage_list_filters_and_orders_by_priority(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $low = $this->report($headers, ['title' => 'Low one'])['id'];
        $high = $this->report($headers, ['title' => 'High one'])['id'];
        $this->patchJson(self::API."/interventions/{$low}", ['intervention_priority_id' => InterventionPriority::where('code', 'p4')->value('id')])->assertOk();
        $this->patchJson(self::API."/interventions/{$high}", ['intervention_priority_id' => InterventionPriority::where('code', 'p1')->value('id'), 'scheduled_at' => '2026-11-18 10:00:00'])->assertOk();

        $titles = collect($this->getJson(self::API.'/interventions?open=1')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['High one', 'Low one'], $titles);
        $this->assertCount(1, $this->getJson(self::API.'/interventions?priority_id='.InterventionPriority::where('code', 'p1')->value('id'))->json('data'));
        $this->assertCount(0, $this->getJson(self::API.'/interventions?open=0')->json('data'));
        $this->assertCount(1, $this->getJson(self::API.'/calendar?from=2026-11-18&to=2026-11-18&kinds[]=intervention')->json('data'));
    }
}
