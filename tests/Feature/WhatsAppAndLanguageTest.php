<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\InterventionStatus;
use App\Models\InterventionType;
use App\Models\MessageTemplate;
use App\Models\PortalAccount;
use App\Models\Product;
use App\Models\Prospect;
use App\Services\MessageTemplateDefaults;
use App\Services\WhatsAppLinkBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class WhatsAppAndLanguageTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        Storage::fake('local');
        $this->product = Product::factory()->create(['name' => 'GestCom']);
    }

    private function prospect(array $over = []): Prospect
    {
        return Prospect::factory()->inStage($this->demoStage)->create($over + ['name' => 'Atlas Trading', 'contact_person' => 'Karim B.', 'phone' => '0555123456']);
    }

    private function schedule(Prospect $p, string $kind, array $over = [])
    {
        return $this->postJson(self::API.'/appointments', $over + [
            'prospect_id' => $p->id, 'appointment_type_id' => AppointmentType::where('code', $kind)->value('id'), 'product_id' => $this->product->id,
            'starts_at' => '2026-11-18 09:00:00', 'ends_at' => '2026-11-18 11:00:00', 'assigned_employee' => 'Samir',
            'meeting_url' => 'https://meet.example.test/room',
        ]);
    }

    // ---- 4.1 link for every kind, without sending it by hand

    public function test_every_appointment_kind_gets_a_prefilled_link_by_default(): void
    {
        $prospect = $this->prospect();
        $hour = 8;

        foreach (['demo', 'meeting', 'training'] as $kind) {
            $hour += 2;
            $r = $this->schedule($prospect, $kind, ['starts_at' => sprintf('2026-11-18 %02d:00:00', $hour), 'ends_at' => sprintf('2026-11-18 %02d:00:00', $hour + 1)])->assertCreated();
            $this->assertStringStartsWith('https://wa.me/213555123456?text=', $r->json('data.whatsapp.url'), $kind);
            $this->assertStringContainsString('Karim B.', $r->json('data.whatsapp.message'), $kind);
            $this->assertFalse($r->json('data.whatsapp.delivery_confirmed'));
        }
    }

    public function test_training_and_accompaniment_always_link_even_when_the_flag_is_not_sent(): void
    {
        $prospect = $this->prospect();

        $training = $this->schedule($prospect, 'training')->assertCreated();
        $this->assertSame('training_first', $training->json('data.whatsapp.template_code'));

        $series = $this->schedule($prospect, 'accompaniment', ['starts_at' => '2026-11-17 14:00:00', 'ends_at' => '2026-11-17 16:00:00'])->assertCreated();
        $items = $series->json('data.appointments');
        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertSame('appointment_accompaniment', $item['whatsapp']['template_code']);
            $this->assertStringContainsString('GestCom', $item['whatsapp']['message']);
        }
        $this->assertNotSame($items[0]['whatsapp']['message'], $items[1]['whatsapp']['message']);   // each session has its own date

        $this->getJson(self::API.'/appointments/'.$items[2]['id'])->assertJsonPath('data.whatsapp.template_code', 'appointment_accompaniment');
    }

    public function test_follow_up_training_uses_the_review_template(): void
    {
        $prospect = $this->prospect();
        $this->schedule($prospect, 'training')->assertCreated();

        $this->schedule($prospect, 'training', ['starts_at' => '2026-11-19 09:00:00', 'ends_at' => '2026-11-19 11:00:00'])->assertCreated()
            ->assertJsonPath('data.whatsapp.template_code', 'training_review');
    }

    public function test_confirming_a_website_demo_produces_the_link(): void
    {
        [, $headers] = $this->signedIn(null, 'karim@example.test');
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product), $headers)->assertCreated();
        $id = Appointment::first()->id;
        $this->assertNull($this->getJson(self::API."/appointments/{$id}")->json('data.whatsapp'));   // still only a hold

        $confirmed = $this->postJson(self::API."/appointments/{$id}/confirm", ['meeting_url' => 'https://meet.example.test/demo'])->assertOk();
        $this->assertSame('appointment_demo', $confirmed->json('data.whatsapp.template_code'));
        $this->assertStringContainsString(rawurlencode('https://meet.example.test/demo'), $confirmed->json('data.whatsapp.url'));
    }

    public function test_no_link_when_opted_out_unconfirmed_or_without_a_usable_phone(): void
    {
        $this->schedule($this->prospect(), 'training', ['send_whatsapp' => false, 'assigned_employee' => 'Samir'])->assertCreated()->assertJsonPath('data.whatsapp', null);
        $this->schedule($this->prospect(['phone' => null]), 'meeting', ['assigned_employee' => 'Lina'])->assertCreated()->assertJsonPath('data.whatsapp', null);
        $this->schedule($this->prospect(['phone' => '12']), 'meeting', ['assigned_employee' => 'Nora'])->assertCreated()->assertJsonPath('data.whatsapp', null);
    }

    public function test_phone_numbers_are_normalised_to_international_format(): void
    {
        $builder = app(WhatsAppLinkBuilder::class);

        foreach (['0555 12 34 56', '+213 555 12 34 56', '00213555123456', '555123456', '(0555) 12-34-56', '213555123456'] as $raw) {
            $this->assertSame('213555123456', $builder->normalisePhone($raw), $raw);
        }
        $this->assertSame('33612345678', $builder->normalisePhone('+33 6 12 34 56 78'));
        $this->assertNull($builder->normalisePhone('abc'));
        $this->assertNull($builder->normalisePhone('12'));
        $this->assertNull($builder->normalisePhone(null));
    }

    // ---- 4.2 language

    public function test_the_template_follows_the_clients_language_with_fallbacks(): void
    {
        $fr = $this->schedule($this->prospect(['language' => 'fr']), 'meeting')->assertCreated();
        $en = $this->schedule($this->prospect(['language' => 'en', 'phone' => '0666123456']), 'meeting', ['starts_at' => '2026-11-18 12:00:00', 'ends_at' => '2026-11-18 13:00:00', 'assigned_employee' => 'Lina'])->assertCreated();
        $ar = $this->schedule($this->prospect(['language' => 'ar', 'phone' => '0777123456']), 'meeting', ['starts_at' => '2026-11-18 14:00:00', 'ends_at' => '2026-11-18 15:00:00', 'assigned_employee' => 'Nora'])->assertCreated();

        $this->assertStringStartsWith('Bonjour', $fr->json('data.whatsapp.message'));
        $this->assertStringStartsWith('Hello', $en->json('data.whatsapp.message'));
        $this->assertStringStartsWith('مرحبا', $ar->json('data.whatsapp.message'));
        $this->assertSame(['fr', 'en', 'ar'], [$fr->json('data.whatsapp.language'), $en->json('data.whatsapp.language'), $ar->json('data.whatsapp.language')]);

        // Missing or deactivated language -> French.
        MessageTemplate::where(['code' => 'appointment_meeting', 'locale' => 'ar'])->update(['is_active' => false]);
        $this->getJson(self::API.'/appointments/'.$ar->json('data.id'))->assertJsonPath('data.whatsapp.language', 'fr');
        MessageTemplate::where(['code' => 'appointment_meeting', 'locale' => 'fr'])->delete();
        $this->getJson(self::API.'/appointments/'.$ar->json('data.id'))->assertJsonPath('data.whatsapp.language', 'en');
    }

    public function test_placeholders_are_all_filled(): void
    {
        MessageTemplate::where(['code' => 'appointment_training', 'locale' => 'fr'])->delete();
        MessageTemplate::where(['code' => 'training_first', 'locale' => 'fr'])->update([
            'body' => '{client_name}|{company_name}|{product_name}|{date}|{time}|{end}|{link}|{employee}|{appointment_type}|{contact}|{product}|{start}',
        ]);

        $message = $this->schedule($this->prospect(), 'training')->assertCreated()->json('data.whatsapp.message');

        $this->assertSame('Karim B.|Atlas Trading|GestCom|18/11/2026|09:00|11:00|https://meet.example.test/room|Samir|Training|Karim B.|GestCom|09:00', $message);
        $this->assertStringNotContainsString('{', $message);
    }

    public function test_language_is_a_prospect_and_customer_field(): void
    {
        $id = $this->postJson(self::API.'/prospects', ['name' => 'Atlas'])->assertCreated()->assertJsonPath('data.language', 'fr')->json('data.id');
        $this->putJson(self::API."/prospects/{$id}", ['language' => 'ar'])->assertOk()->assertJsonPath('data.language', 'ar');
        $this->postJson(self::API.'/prospects', ['name' => 'Beta', 'language' => 'en'])->assertCreated()->assertJsonPath('data.language', 'en');
        $this->postJson(self::API.'/prospects', ['name' => 'Gamma', 'language' => 'de'])->assertStatus(422)->assertJsonValidationErrors('language');
        $this->putJson(self::API."/prospects/{$id}", ['language' => null])->assertStatus(422);

        $this->postJson(self::API."/prospects/{$id}/convert", [])->assertOk();
        $this->getJson(self::API."/customers/{$id}")->assertJsonPath('data.prospect.language', 'ar');
    }

    public function test_website_language_flows_to_the_prospect(): void
    {
        [$account, $headers] = $this->signedIn(null, 'karim@example.test');
        $account->update(['locale' => 'ar']);
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null, 'quotation'), $headers)->assertCreated();

        $prospect = Prospect::query()->where('source', 'website')->firstOrFail();
        $this->assertSame('ar', $prospect->language);

        $this->patchJson(self::PORTAL.'/me', ['locale' => 'en'], $headers)->assertOk();
        $this->assertSame('en', $prospect->fresh()->language);
        $this->assertSame('en', PortalAccount::first()->locale);
    }

    // ---- 4.2 templates audit

    public function test_templates_exist_for_every_kind_in_every_language(): void
    {
        foreach (['demo', 'meeting', 'training', 'accompaniment'] as $kind) {
            foreach (['ar', 'fr', 'en'] as $language) {
                $this->assertTrue(MessageTemplate::where(['code' => "appointment_{$kind}", 'locale' => $language, 'is_active' => true])->exists(), "{$kind}/{$language}");
            }
        }

        $this->getJson(self::API.'/settings/message-templates/audit')->assertOk()->assertJsonPath('data.missing', [])->assertJsonFragment(['languages' => ['ar', 'fr', 'en']]);
    }

    public function test_audit_reports_missing_inactive_and_empty_templates_and_install_repairs_them(): void
    {
        MessageTemplate::where(['code' => 'appointment_demo', 'locale' => 'ar'])->delete();
        MessageTemplate::where(['code' => 'appointment_meeting', 'locale' => 'en'])->update(['is_active' => false]);
        MessageTemplate::where(['code' => 'appointment_training', 'locale' => 'fr'])->update(['body' => '   ']);

        $missing = collect($this->getJson(self::API.'/settings/message-templates/audit')->json('data.missing'))->keyBy(fn ($m) => $m['code'].'/'.$m['language']);
        $this->assertSame('missing', $missing['appointment_demo/ar']['reason']);
        $this->assertSame('inactive', $missing['appointment_meeting/en']['reason']);
        $this->assertSame('empty', $missing['appointment_training/fr']['reason']);

        $this->assertSame(1, MessageTemplateDefaults::install());   // only the deleted one; edits and deactivations are respected
        $this->assertCount(2, MessageTemplateDefaults::audit()['missing']);
    }

    // ---- Module 2 follow-ups

    public function test_customers_cannot_reopen_a_closed_intervention_only_employees_can(): void
    {
        [, , $headers] = $this->customerWith($this->product);
        $id = $this->postJson(self::PORTAL.'/interventions', [
            'intervention_type_id' => InterventionType::where('code', 'bug')->value('id'), 'product_id' => $this->product->id, 'title' => 'x', 'description' => 'y',
        ], $headers)->assertCreated()->json('data.id');
        $status = fn (string $code) => InterventionStatus::where('code', $code)->value('id');

        foreach (['under_review', 'waiting_customer', 'closed'] as $code) {
            $this->postJson(self::API."/interventions/{$id}/transition", ['status_id' => $status($code)])->assertOk();
        }

        $this->getJson(self::PORTAL."/interventions/{$id}/transitions", $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::PORTAL."/interventions/{$id}/transition", ['status_id' => $status('reopened')], $headers)
            ->assertStatus(422)->assertJsonPath('error_code', 'INTERVENTION_CLOSED');
        $this->postJson(self::PORTAL."/interventions/{$id}/messages", ['body' => 'hello'], $headers)->assertStatus(422);

        $this->postJson(self::API."/interventions/{$id}/reopen")->assertOk()->assertJsonPath('data.can_reply', true);
    }

    public function test_release_ticket_type_is_retired(): void
    {
        $this->assertNull(InterventionType::where('code', 'release')->where('is_active', true)->first());
        $this->assertNull(InterventionType::where('code', 'update')->where('is_active', true)->first());
    }
}
