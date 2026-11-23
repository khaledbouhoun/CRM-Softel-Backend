<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Models\InterventionAttachment;
use App\Models\InterventionStatus;
use App\Models\InterventionType;
use App\Models\PortalAccount;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class InterventionClosureAndChatTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    /** @var array<string, string> */
    private array $headers;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        Storage::fake('local');
        $this->product = Product::factory()->create(['name' => 'GestCom']);
        [, , $this->headers] = $this->customerWith($this->product);
        $this->id = $this->postJson(self::PORTAL.'/interventions', [
            'intervention_type_id' => InterventionType::where('code', 'bug')->value('id'), 'product_id' => $this->product->id,
            'title' => 'Invoice total wrong', 'description' => 'Totals ignore the discount.',
        ], $this->headers)->assertCreated()->json('data.id');
    }

    private function st(string $code): int
    {
        return InterventionStatus::where('code', $code)->value('id');
    }

    private function staffMove(string $code, array $extra = [])
    {
        return $this->postJson(self::API."/interventions/{$this->id}/transition", ['status_id' => $this->st($code)] + $extra, ['X-Employee-Name' => 'Samir']);
    }

    /** Multipart employee reply (files cannot travel as JSON). */
    private function staffReply(array $data)
    {
        return $this->post(self::API."/interventions/{$this->id}/messages", $data, ['Accept' => 'application/json', 'X-Employee-Name' => 'Samir']);
    }

    // ---- 2.4 closure & reply locking

    public function test_closed_intervention_refuses_customer_replies_and_files(): void
    {
        $this->staffMove('rejected')->assertOk();

        $this->postJson(self::PORTAL."/interventions/{$this->id}/messages", ['body' => 'Still broken'], $this->headers)
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERVENTION_CLOSED');
        $this->postJson(self::PORTAL."/interventions/{$this->id}/attachments", ['file' => UploadedFile::fake()->create('a.png', 10, 'image/png')], $this->headers)
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERVENTION_CLOSED');

        $detail = $this->getJson(self::PORTAL."/interventions/{$this->id}", $this->headers)->assertOk();
        $detail->assertJsonPath('data.can_reply', false)->assertJsonPath('data.closed_notice', 'This intervention is closed. New replies are disabled.');
        $this->getJson(self::API."/interventions/{$this->id}")->assertJsonPath('data.can_reply', false)->assertJsonPath('data.is_closed', true);
    }

    public function test_closed_intervention_refuses_customer_visible_employee_replies_but_not_internal_notes(): void
    {
        $this->staffMove('rejected')->assertOk();

        $this->postJson(self::API."/interventions/{$this->id}/messages", ['visibility' => 'customer', 'body' => 'Hello'])
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERVENTION_CLOSED');
        $this->postJson(self::API."/interventions/{$this->id}/messages", ['visibility' => 'internal', 'body' => 'Note to self'])->assertCreated();
    }

    public function test_employee_can_reopen_and_replies_work_again(): void
    {
        $this->staffMove('rejected')->assertOk();

        $reopened = $this->postJson(self::API."/interventions/{$this->id}/reopen", ['note' => 'New information'], ['X-Employee-Name' => 'Samir'])->assertOk();
        $reopened->assertJsonPath('data.status.code', 'new')->assertJsonPath('data.can_reply', true)->assertJsonPath('data.closed_at', null);

        $this->postJson(self::PORTAL."/interventions/{$this->id}/messages", ['body' => 'Thanks'], $this->headers)->assertOk();
        $this->assertSame('reopened', $this->getJson(self::API."/interventions/{$this->id}")->json('data.messages.1.kind'));
    }

    public function test_reopen_rules(): void
    {
        $this->postJson(self::API."/interventions/{$this->id}/reopen")->assertStatus(422)->assertJsonPath('error_code', 'INTERVENTION_NOT_CLOSED');

        $this->staffMove('rejected')->assertOk();
        $this->postJson(self::API."/interventions/{$this->id}/reopen", ['status_id' => $this->st('closed')])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_REOPEN_STATUS');
        $this->postJson(self::API."/interventions/{$this->id}/reopen", ['status_id' => $this->st('in_progress')])->assertOk()->assertJsonPath('data.status.code', 'in_progress');
    }

    // ---- 2.1 sender metadata and timeline events

    public function test_status_changes_become_timeline_events_with_actor_old_and_new_status(): void
    {
        $this->staffMove('under_review')->assertOk();

        $events = collect($this->getJson(self::API."/interventions/{$this->id}")->json('data.messages'))->where('kind', 'status_change')->values();
        $this->assertCount(1, $events);
        $event = $events[0]['event'];
        $this->assertSame('Samir', $event['actor']['name']);
        $this->assertSame('New', $event['old_status']['name']);
        $this->assertSame('Under review', $event['new_status']['name']);
        $this->assertNotEmpty($event['at']);
        $this->assertSame('system', $events[0]['sender']['role']);
    }

    public function test_customers_see_events_without_employee_names_and_replies_with_sender_roles(): void
    {
        $this->staffMove('under_review')->assertOk();
        $this->postJson(self::API."/interventions/{$this->id}/messages", ['visibility' => 'customer', 'body' => 'Looking into it'], ['X-Employee-Name' => 'Samir'])->assertCreated();
        $this->postJson(self::PORTAL."/interventions/{$this->id}/messages", ['body' => 'Thank you'], $this->headers)->assertOk();

        $response = $this->getJson(self::PORTAL."/interventions/{$this->id}", $this->headers)->assertOk();
        $messages = collect($response->json('data.messages'));

        $this->assertSame(['system', 'employee', 'client'], $messages->pluck('sender.role')->all());
        $this->assertSame('Under review', $messages[0]['event']['new_status']['name']);
        $this->assertStringNotContainsString('Samir', $response->getContent());
        $this->assertSame('Support', $messages[1]['sender']['name']);

        $staff = collect($this->getJson(self::API."/interventions/{$this->id}")->json('data.messages'));
        $this->assertSame(['system', 'employee', 'client'], $staff->pluck('sender.role')->all());
        $this->assertSame('Samir', $staff[1]['sender']['name']);
    }

    public function test_customer_avatar_is_part_of_the_sender(): void
    {
        $this->staffMove('under_review')->assertOk();
        PortalAccount::query()->update(['avatar_url' => 'https://img.example.test/me.png']);
        $this->postJson(self::PORTAL."/interventions/{$this->id}/messages", ['body' => 'Hi'], $this->headers)->assertOk();

        $client = collect($this->getJson(self::API."/interventions/{$this->id}")->json('data.messages'))->firstWhere('sender.role', 'client');
        $this->assertSame('https://img.example.test/me.png', $client['sender']['avatar_url']);
    }

    // ---- 2.2 attachments

    public function test_employee_replies_with_documents_and_installers(): void
    {
        $response = $this->staffReply([
            'visibility' => 'customer', 'body' => 'Here are the files',
            'files' => [
                UploadedFile::fake()->create('guide.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
                UploadedFile::fake()->create('report.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                UploadedFile::fake()->create('patch.exe', 2000, 'application/x-msdownload'),
                UploadedFile::fake()->create('bundle.zip', 100, 'application/zip'),
            ],
        ])->assertCreated();

        $message = collect($response->json('data.messages'))->firstWhere('kind', 'message');
        $this->assertCount(5, $message['attachments']);
        $this->assertSame('employee', $message['attachments'][0]['uploaded_by']);
        foreach ($message['attachments'] as $file) {
            Storage::disk('local')->assertExists(InterventionAttachment::find($file['id'])->path);
        }

        $rar = $this->staffReply(['visibility' => 'customer', 'files' => [UploadedFile::fake()->create('tools.rar', 100, 'application/vnd.rar')]])->assertCreated();
        $this->assertSame('tools.rar', collect($rar->json('data.messages'))->last()['attachments'][0]['name']);
    }

    public function test_employee_upload_validation(): void
    {
        $this->staffReply(['visibility' => 'customer', 'body' => 'x', 'files' => [UploadedFile::fake()->create('shell.php', 1, 'text/plain')]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->staffReply(['visibility' => 'customer', 'body' => 'x', 'files' => [UploadedFile::fake()->create('big.zip', config('crm.uploads.staff_attachment_max_kb') + 1, 'application/zip')]])->assertStatus(422);
        $this->staffReply(['visibility' => 'customer', 'body' => 'x', 'files' => array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.txt", 1), range(1, 6))])->assertStatus(422);
        $this->staffReply(['visibility' => 'customer'])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_customer_sees_previews_and_downloads_only_customer_visible_files(): void
    {
        $this->staffReply(['visibility' => 'customer', 'body' => 'Screenshot', 'files' => [UploadedFile::fake()->create('shot.png', 10, 'image/png'), UploadedFile::fake()->create('patch.exe', 10, 'application/x-msdownload')]])->assertCreated();
        $this->staffReply(['visibility' => 'internal', 'body' => 'Private', 'files' => [UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf')]])->assertCreated();

        $attachments = $this->getJson(self::PORTAL."/interventions/{$this->id}", $this->headers)->assertOk()->json('data.attachments');
        $this->assertSame(['shot.png', 'patch.exe'], array_column($attachments, 'name'));
        $this->assertNotNull($attachments[0]['preview_url']);
        $this->assertNull($attachments[1]['preview_url']);

        $png = $this->get($attachments[0]['preview_url'], $this->headers)->assertOk();
        $this->assertStringContainsString('inline', $png->headers->get('content-disposition'));
        $this->assertSame('nosniff', $png->headers->get('x-content-type-options'));
        $this->assertStringContainsString('attachment', $this->get($attachments[0]['download_url'], $this->headers)->assertOk()->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', $this->get(str_replace('/attachments/'.$attachments[1]['id'], '/attachments/'.$attachments[1]['id'].'/preview', $attachments[1]['download_url']), $this->headers)->assertOk()->headers->get('content-disposition'));

        $secret = InterventionAttachment::where('original_name', 'secret.pdf')->first();
        $this->get(self::PORTAL."/interventions/{$this->id}/attachments/{$secret->id}", $this->headers)->assertStatus(404);
        $this->get(self::API."/interventions/{$this->id}/attachments/{$secret->id}")->assertOk();   // staff may download internal files
    }

    public function test_attachments_of_another_customer_stay_unreachable(): void
    {
        $this->staffReply(['visibility' => 'customer', 'body' => 'x', 'files' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]])->assertCreated();
        $file = InterventionAttachment::first();
        [, , $other] = $this->customerWith(Product::factory()->create(), 'other@example.test');

        $this->get(self::PORTAL."/interventions/{$this->id}/attachments/{$file->id}", $other)->assertStatus(404);
        $this->get(self::PORTAL."/interventions/{$this->id}/attachments/{$file->id}/preview", $other)->assertStatus(404);
    }

    // ---- 2.3 the "update" type is retired from support tickets

    public function test_update_type_is_deactivated_and_cannot_open_interventions(): void
    {
        $this->assertNull(InterventionType::where('code', 'update')->where('is_active', true)->first());

        $legacy = InterventionType::query()->create(['code' => 'update', 'name' => 'Product update', 'source' => 'employee', 'is_active' => false]);
        $this->postJson(self::API.'/interventions', ['intervention_type_id' => $legacy->id, 'title' => 'x', 'description' => 'y'])
            ->assertStatus(422)->assertJsonPath('error_code', 'TYPE_UNAVAILABLE');
        $this->assertSame(0, Intervention::where('intervention_type_id', $legacy->id)->count());
    }
}
