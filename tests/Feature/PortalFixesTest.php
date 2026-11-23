<?php

namespace Tests\Feature;

use App\Models\InterventionAttachment;
use App\Models\InterventionType;
use App\Models\Pack;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class PortalFixesTest extends TestCase
{
    use CreatesPortalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        Storage::fake('local');
    }

    public function test_owned_products_with_a_pack_load_without_lazy_loading_errors(): void
    {
        $pack = Pack::query()->create(['name' => 'GestCom editions', 'code' => 'gestcom']);
        $product = Product::factory()->create(['name' => 'GestCom Pro', 'pack_id' => $pack->id]);
        [, , $headers] = $this->customerWith($product);

        $this->getJson(self::PORTAL.'/products', $headers)->assertOk()
            ->assertJsonPath('data.0.pack.code', 'gestcom')
            ->assertJsonPath('data.0.pack.name', 'GestCom editions');
    }

    /** @return array{0: int, 1: array<string, string>} */
    private function openIntervention(): array
    {
        $product = Product::factory()->create(['name' => 'GestCom']);
        [, , $headers] = $this->customerWith($product);
        $id = $this->postJson(self::PORTAL.'/interventions', [
            'intervention_type_id' => InterventionType::where('code', 'bug')->value('id'), 'product_id' => $product->id,
            'title' => 'Invoice total wrong', 'description' => 'Totals ignore the discount.',
        ], $headers)->assertCreated()->json('data.id');

        return [$id, $headers];
    }

    public function test_a_file_uploaded_by_the_customer_appears_inside_the_conversation_for_staff(): void
    {
        [$id, $headers] = $this->openIntervention();

        $this->postJson(self::PORTAL."/interventions/{$id}/attachments", ['file' => UploadedFile::fake()->create('screen.png', 10, 'image/png')], $headers)->assertCreated();

        $staff = $this->getJson(self::API."/interventions/{$id}")->assertOk()->json('data');
        $withFile = collect($staff['messages'])->first(fn ($m) => ! empty($m['attachments']));
        $this->assertNotNull($withFile, 'the file is part of a message in the staff timeline');
        $this->assertSame('customer', $withFile['visibility']);
        $this->assertSame('screen.png', $withFile['attachments'][0]['name']);
        $this->assertSame('customer', InterventionAttachment::first()->visibility);

        // The customer sees it in the thread as their own message.
        $mine = collect($this->getJson(self::PORTAL."/interventions/{$id}", $headers)->json('data.messages'))->first(fn ($m) => ! empty($m['attachments']));
        $this->assertSame('you', $mine['author']);
    }

    public function test_a_reply_can_carry_a_file_with_or_without_text(): void
    {
        [$id, $headers] = $this->openIntervention();

        $this->post(self::PORTAL."/interventions/{$id}/messages", ['body' => 'See attached', 'file' => UploadedFile::fake()->create('log.pdf', 10, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertOk();
        $this->post(self::PORTAL."/interventions/{$id}/messages", ['file' => UploadedFile::fake()->create('more.png', 10, 'image/png')], $headers + ['Accept' => 'application/json'])->assertOk();
        $this->postJson(self::PORTAL."/interventions/{$id}/messages", [], $headers)->assertStatus(422);

        $messages = collect($this->getJson(self::PORTAL."/interventions/{$id}", $headers)->json('data.messages'))->filter(fn ($m) => ! empty($m['attachments']));
        $this->assertSame(['log.pdf', 'more.png'], $messages->map(fn ($m) => $m['attachments'][0]['name'])->values()->all());
    }

    public function test_staff_can_send_a_private_hotfix_patch_that_the_customer_downloads(): void
    {
        [$id, $headers] = $this->openIntervention();

        $this->post(self::API."/interventions/{$id}/messages", [
            'visibility' => 'customer', 'body' => 'Patch for your invoice bug', 'is_hotfix' => '1',
            'files' => [UploadedFile::fake()->create('patch-4.2.1.exe', 20, 'application/x-msdownload')],
        ], ['Accept' => 'application/json', 'X-Employee-Name' => 'Samir'])->assertCreated();

        $this->assertTrue(InterventionAttachment::first()->is_hotfix);

        $staff = collect($this->getJson(self::API."/interventions/{$id}")->json('data.messages'))->first(fn ($m) => ! empty($m['attachments']));
        $this->assertTrue($staff['attachments'][0]['is_hotfix']);

        $file = collect($this->getJson(self::PORTAL."/interventions/{$id}", $headers)->assertOk()->json('data.attachments'))->first();
        $this->assertTrue($file['is_hotfix']);
        $this->get(self::PORTAL."/interventions/{$id}/attachments/{$file['id']}", $headers)->assertOk();
    }

    public function test_a_normal_reply_file_is_not_a_hotfix_and_internal_notes_never_are(): void
    {
        [$id] = $this->openIntervention();

        $this->post(self::API."/interventions/{$id}/messages", ['visibility' => 'customer', 'body' => 'Screenshot', 'files' => [UploadedFile::fake()->create('a.png', 10, 'image/png')]], ['Accept' => 'application/json'])->assertCreated();
        $this->post(self::API."/interventions/{$id}/messages", ['visibility' => 'internal', 'body' => 'note', 'is_hotfix' => '1', 'files' => [UploadedFile::fake()->create('b.png', 10, 'image/png')]], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame([false, false], InterventionAttachment::orderBy('id')->get()->map(fn ($a) => (bool) $a->is_hotfix)->all());
    }

    public function test_an_expired_maintenance_plan_blocks_hotfix_downloads(): void
    {
        $product = Product::factory()->create(['name' => 'GestCom']);
        [$prospect, , $headers] = $this->customerWith($product);
        $id = $this->postJson(self::PORTAL.'/interventions', [
            'intervention_type_id' => InterventionType::where('code', 'bug')->value('id'), 'product_id' => $product->id,
            'title' => 'Bug', 'description' => 'Broken.',
        ], $headers)->assertCreated()->json('data.id');
        $this->post(self::API."/interventions/{$id}/messages", ['visibility' => 'customer', 'body' => 'Patch', 'is_hotfix' => '1', 'files' => [UploadedFile::fake()->create('p.zip', 10, 'application/zip')]], ['Accept' => 'application/json'])->assertCreated();
        $fileId = InterventionAttachment::first()->id;

        \App\Models\Subscription::query()->create(['prospect_id' => $prospect->id, 'type' => 'maintenance', 'status' => 'expired', 'starts_at' => '2025-01-01', 'expires_at' => '2025-12-31']);

        $this->getJson(self::PORTAL."/interventions/{$id}/attachments/{$fileId}", $headers)->assertStatus(403)->assertJsonPath('error_code', 'MAINTENANCE_REQUIRED');
    }
}
