<?php

namespace Tests\Feature;

use App\Models\Pack;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use App\Support\Wilayas;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class PortalOnboardingTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $this->product = Product::factory()->create(['name' => 'GestCom', 'is_active_on_web' => true]);
    }

    private function google(array $over = [])
    {
        return $this->postJson(self::PORTAL.'/auth/google', $over + [
            'google_id' => 'g-123', 'email' => 'sara@example.test', 'name' => 'Sara', 'locale' => 'fr',
            'avatar_url' => 'https://lh3.googleusercontent.com/a/sara.png',
        ], $this->keyHeaders());
    }

    private function headersFor(string $token): array
    {
        return $this->keyHeaders() + ['Authorization' => 'Bearer '.$token];
    }

    // ---- 5.1 first login -> new request

    public function test_a_new_account_is_sent_to_the_new_request_form_and_then_to_the_overview(): void
    {
        $register = $this->postJson(self::PORTAL.'/auth/register', ['name' => 'Ali', 'email' => 'ali@example.test', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'], $this->keyHeaders())->assertCreated();
        $register->assertJsonPath('data.onboarding.required', true)->assertJsonPath('data.onboarding.redirect_to', 'new_request');
        $this->assertSame(['demo', 'trial'], array_column($register->json('data.onboarding.options'), 'type'));
        $this->assertSame(['Presentation', 'Demo version'], array_column($register->json('data.onboarding.options'), 'label'));

        $headers = $this->headersFor($register->json('data.token'));
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, '2026-11-17 10:00:00'), $headers)->assertCreated();

        $login = $this->postJson(self::PORTAL.'/auth/login', ['email' => 'ali@example.test', 'password' => 'secret-pass-1'], $this->keyHeaders())->assertOk();
        $login->assertJsonPath('data.onboarding.required', false)->assertJsonPath('data.onboarding.redirect_to', 'overview');
    }

    public function test_google_first_sign_in_is_onboarded_and_stores_the_avatar(): void
    {
        $first = $this->google()->assertOk();
        $first->assertJsonPath('data.onboarding.redirect_to', 'new_request')
            ->assertJsonPath('data.account.avatar_url', 'https://lh3.googleusercontent.com/a/sara.png');

        $headers = $this->headersFor($first->json('data.token'));
        $this->getJson(self::PORTAL.'/me', $headers)->assertJsonPath('data.avatar_url', 'https://lh3.googleusercontent.com/a/sara.png')->assertJsonPath('data.onboarding.required', true);
        $this->getJson(self::PORTAL.'/profile', $headers)->assertJsonPath('data.account.avatar_url', 'https://lh3.googleusercontent.com/a/sara.png');
    }

    public function test_google_avatar_follows_google_but_a_missing_picture_never_wipes_it(): void
    {
        $this->google()->assertOk();

        $this->google(['avatar_url' => 'https://lh3.googleusercontent.com/a/new.png'])->assertOk()->assertJsonPath('data.account.avatar_url', 'https://lh3.googleusercontent.com/a/new.png');
        $this->google(['avatar_url' => null])->assertOk()->assertJsonPath('data.account.avatar_url', 'https://lh3.googleusercontent.com/a/new.png');
        $this->assertSame(1, PortalAccount::count());
    }

    public function test_google_linking_a_password_account_adopts_the_picture(): void
    {
        PortalAccount::query()->create(['name' => 'Sara', 'email' => 'sara@example.test', 'password' => 'secret-pass-1']);

        $this->google()->assertOk();
        $this->assertSame('https://lh3.googleusercontent.com/a/sara.png', PortalAccount::first()->avatar_url);
        $this->assertSame(1, PortalAccount::count());
    }

    public function test_avatar_must_be_an_https_url(): void
    {
        foreach (['http://example.test/a.png', 'javascript:alert(1)', 'not a url'] as $bad) {
            $this->google(['avatar_url' => $bad])->assertStatus(422)->assertJsonValidationErrors('avatar_url', 'errors');
        }
        $this->assertSame(0, PortalAccount::count());
    }

    // ---- 5.1 autofill

    public function test_the_first_request_is_remembered_and_the_next_one_only_needs_the_product(): void
    {
        [$account, $headers] = $this->signedIn();
        $other = Product::factory()->create(['name' => 'GestPaie']);

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null, 'quotation'), $headers)->assertCreated();

        $profile = $this->getJson(self::PORTAL.'/profile', $headers)->assertOk();
        $profile->assertJsonPath('data.onboarding.required', false)
            ->assertJsonPath('data.request_defaults.company', 'Atlas Trading')
            ->assertJsonPath('data.request_defaults.contact_name', 'Karim B.')
            ->assertJsonPath('data.request_defaults.phone', '0555123456')
            ->assertJsonPath('data.request_defaults.wilaya', '16 - Alger')
            ->assertJsonPath('data.account.profile.wilaya_code', '16');

        $this->postJson(self::PORTAL.'/requests', ['type' => 'quotation', 'product_id' => $other->id], $headers)->assertCreated();

        $second = PortalRequest::query()->where('product_id', $other->id)->firstOrFail();
        $this->assertSame(['Atlas Trading', 'Karim B.', '0555123456', '16 - Alger', 'Bab Ezzouar', 'Manager'], [
            $second->company, $second->contact_name, $second->phone, $second->wilaya, $second->city, $second->job_title,
        ]);
        $this->assertSame($account->id, $second->portal_account_id);
    }

    public function test_values_typed_in_the_form_win_over_the_saved_ones_and_blank_values_never_erase_them(): void
    {
        [$account, $headers] = $this->signedIn();
        $other = Product::factory()->create();
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null, 'quotation'), $headers)->assertCreated();

        $this->postJson(self::PORTAL.'/requests', ['type' => 'quotation', 'product_id' => $other->id, 'city' => 'Hydra', 'job_title' => null], $headers)->assertCreated();

        $this->assertSame('Hydra', $account->fresh()->city);
        $this->assertSame('Manager', $account->fresh()->job_title);
        $this->assertSame('Atlas Trading', $account->fresh()->company);
    }

    public function test_the_customer_file_prefills_details_for_an_existing_customer(): void
    {
        [$prospect, , $headers] = $this->customerWith($this->product);
        $prospect->update(['name' => 'Delta SARL', 'contact_person' => 'Nadia', 'phone' => '0661000000', 'city' => 'Oran', 'address' => 'Oran']);

        $this->getJson(self::PORTAL.'/me', $headers)
            ->assertJsonPath('data.request_defaults.company', 'Delta SARL')
            ->assertJsonPath('data.request_defaults.contact_name', 'Nadia')
            ->assertJsonPath('data.request_defaults.wilaya', '31 - Oran')
            ->assertJsonPath('data.onboarding.required', true);   // never asked for anything on the website yet
    }

    public function test_a_blank_form_without_saved_details_still_lists_what_is_missing(): void
    {
        [, $headers] = $this->signedIn();

        $this->postJson(self::PORTAL.'/requests', [], $headers)->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'product_id', 'company', 'phone', 'wilaya', 'city'], 'errors');
    }

    // ---- 5.2 profile page

    public function test_profile_shows_details_history_and_current_status(): void
    {
        [, $headers] = $this->signedIn();
        $this->getJson(self::PORTAL.'/profile', $headers)->assertJsonPath('data.status.account_type', 'visitor')->assertJsonPath('data.requests', []);

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, '2026-11-17 10:00:00'), $headers)->assertCreated();

        $profile = $this->getJson(self::PORTAL.'/profile', $headers)->assertOk();
        $profile->assertJsonPath('data.status.account_type', 'prospect')->assertJsonPath('data.status.open_requests', 1)
            ->assertJsonPath('data.status.latest_request.type', 'demo')->assertJsonPath('data.status.latest_request.status', 'pending')
            ->assertJsonPath('meta.requests_total', 1)->assertJsonCount(1, 'data.requests');
        $this->assertSame('GestCom', $profile->json('data.requests.0.product.name'));
        $this->assertArrayNotHasKey('notes', (array) $profile->json('data.status'));
    }

    public function test_profile_of_a_customer_and_isolation_between_accounts(): void
    {
        [, , $mine] = $this->customerWith($this->product);
        [, $other] = $this->signedIn(null, 'zed@example.test');
        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->product, null, 'quotation'), $other)->assertCreated();

        $this->getJson(self::PORTAL.'/profile', $mine)->assertJsonPath('data.status.account_type', 'customer')->assertJsonPath('data.status.owned_products', 1)->assertJsonPath('data.requests', []);
        $this->getJson(self::PORTAL.'/profile')->assertStatus(401);
    }

    public function test_profile_details_can_be_edited_with_the_same_wilaya_rules(): void
    {
        [$account, $headers] = $this->signedIn();

        $this->patchJson(self::PORTAL.'/me', ['company' => 'Atlas', 'wilaya' => 'oran', 'city' => 'Es Senia', 'phone' => '0555123456'], $headers)->assertOk()
            ->assertJsonPath('data.profile.wilaya', '31 - Oran')->assertJsonPath('data.profile.wilaya_code', '31')->assertJsonPath('data.profile.company', 'Atlas');
        $this->patchJson(self::PORTAL.'/me', ['wilaya' => 'Atlantis'], $headers)->assertStatus(422)->assertJsonValidationErrors('wilaya', 'errors');
        $this->patchJson(self::PORTAL.'/me', ['avatar_url' => 'https://evil.test/x.png'], $headers)->assertOk();
        $this->assertNull($account->fresh()->avatar_url);   // the picture only comes from Google
    }

    // ---- 5.3 wilayas

    public function test_there_are_58_wilayas_with_unique_codes(): void
    {
        $all = Wilayas::all();
        $this->assertCount(58, $all);
        $this->assertSame(range(1, 58), array_map('intval', array_column($all, 'code')));
        $this->assertCount(58, array_unique(array_column($all, 'name')));
        $labels = array_column($all, 'label');
        $this->assertContains('16 - Alger', $labels);
        $this->assertContains('31 - Oran', $labels);
        $this->assertContains('58 - El Meniaa', $labels);
    }

    public function test_wilaya_lists_are_served_to_the_website_and_to_staff(): void
    {
        $this->getJson(self::PORTAL.'/catalog/wilayas', $this->keyHeaders())->assertOk()->assertJsonCount(58, 'data')
            ->assertJsonPath('data.15.label', '16 - Alger')->assertJsonPath('data.30.name_ar', 'وهران');
        $this->getJson(self::PORTAL.'/catalog/wilayas')->assertStatus(401);

        $this->getJson(self::API.'/lookups')->assertOk()->assertJsonCount(58, 'data.wilayas');
    }

    public function test_every_way_of_writing_a_wilaya_is_stored_as_the_standard_label(): void
    {
        [, $headers] = $this->signedIn();

        foreach (['Alger' => 'ALGER', '16' => '16', '16 - Alger' => '16 - alger', 'ar' => 'الجزائر', 'padded' => '  016  ', 'accent' => 'Béjaïa'] as $case => $input) {
            $payload = $this->requestPayload(Product::factory()->create(), null, 'quotation');
            $this->postJson(self::PORTAL.'/requests', ['wilaya' => $input] + $payload, $headers)->assertCreated();
            $this->assertSame($case === 'accent' ? '06 - Béjaïa' : '16 - Alger', PortalRequest::latest('id')->value('wilaya'), $case);
        }

        $this->assertSame('16 - Alger', Prospect::first()->address);
    }

    public function test_unknown_or_inconsistent_wilayas_are_rejected(): void
    {
        [, $headers] = $this->signedIn();

        foreach (['Atlantis', '59', '0', '16 - Oran', ''] as $bad) {
            $this->postJson(self::PORTAL.'/requests', ['wilaya' => $bad] + $this->requestPayload($this->product, null, 'quotation'), $headers)
                ->assertStatus(422)->assertJsonValidationErrors('wilaya', 'errors');
        }
        $this->assertSame(0, PortalRequest::count());
    }

    // ---- 5.4 checklist with packs

    public function test_checklist_groups_pack_alternatives_and_independent_products(): void
    {
        $pack = Pack::query()->create(['name' => 'GestCom editions', 'code' => 'gestcom']);
        $std = Product::factory()->create(['name' => 'GestCom Standard', 'pack_id' => $pack->id, 'is_active_on_web' => true]);
        $pro = Product::factory()->create(['name' => 'GestCom Pro', 'pack_id' => $pack->id, 'is_active_on_web' => true]);
        $hidden = Product::factory()->create(['name' => 'Hidden', 'pack_id' => $pack->id, 'is_active_on_web' => false]);

        $groups = collect($this->getJson(self::PORTAL.'/catalog/checklist', $this->keyHeaders())->assertOk()->json('data'));
        $packGroup = $groups->firstWhere('type', 'pack');
        $this->assertSame('single', $packGroup['selection']);
        $this->assertSame('gestcom', $packGroup['pack']['code']);
        $this->assertEqualsCanonicalizing([$std->id, $pro->id], array_column($packGroup['products'], 'id'));
        $this->assertNotContains($hidden->id, array_column($packGroup['products'], 'id'));
        $byId = collect($packGroup['products'])->keyBy('id');
        $this->assertSame([$pro->id], $byId[$std->id]['exclusive_with']);
        $this->assertSame([$std->id], $byId[$pro->id]['exclusive_with']);

        $independent = $groups->firstWhere('type', 'independent');
        $this->assertSame('multiple', $independent['selection']);
        $this->assertSame([$this->product->id], array_column($independent['products'], 'id'));
        $this->assertSame([], $independent['products'][0]['exclusive_with']);
    }

    public function test_independent_products_can_be_combined_but_two_of_one_pack_cannot(): void
    {
        [, $headers] = $this->signedIn();
        $pack = Pack::query()->create(['name' => 'GestCom editions', 'code' => 'gestcom']);
        $std = Product::factory()->create(['pack_id' => $pack->id]);
        $pro = Product::factory()->create(['pack_id' => $pack->id]);
        $paie = Product::factory()->create();
        $payload = $this->requestPayload($this->product, null, 'quotation');
        unset($payload['product_id']);

        $this->postJson(self::PORTAL.'/requests', ['product_ids' => [$std->id, $pro->id]] + $payload, $headers)->assertStatus(422)->assertJsonValidationErrors('product_ids', 'errors');
        $this->assertSame(0, PortalRequest::count());

        $this->postJson(self::PORTAL.'/requests', ['product_ids' => [$std->id, $paie->id, $this->product->id]] + $payload, $headers)->assertCreated();
        $this->assertSame(3, PortalRequest::count());
        $this->assertSame(1, Prospect::count());   // one customer file for the whole checklist
    }
}
