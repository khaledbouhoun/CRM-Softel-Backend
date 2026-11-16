<?php

namespace Tests\Feature;

use App\Models\PortalAccount;
use App\Models\Product;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class PortalSecurityTest extends TestCase
{
    use CreatesPortalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
    }

    public function test_portal_api_is_disabled_without_a_configured_service_key(): void
    {
        config(['crm.portal.service_key' => null]);

        $this->getJson(self::PORTAL.'/catalog/products', $this->keyHeaders())->assertStatus(503)->assertJsonPath('error_code', 'PORTAL_DISABLED');
    }

    public function test_service_key_is_required_and_checked(): void
    {
        $this->getJson(self::PORTAL.'/catalog/products')->assertStatus(401)->assertJsonPath('error_code', 'INVALID_SERVICE_KEY');
        $this->getJson(self::PORTAL.'/catalog/products', ['X-Portal-Key' => 'nope'])->assertStatus(401);
        $this->getJson(self::PORTAL.'/catalog/products', $this->keyHeaders())->assertOk();
    }

    public function test_customer_routes_need_a_customer_token_not_just_the_service_key(): void
    {
        $this->getJson(self::PORTAL.'/me', $this->keyHeaders())->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->getJson(self::PORTAL.'/me', $this->keyHeaders() + ['Authorization' => 'Bearer '.str_repeat('a', 64)])->assertStatus(401);
    }

    public function test_service_key_never_opens_the_staff_api_and_staff_api_stays_lan_only(): void
    {
        $this->getJson(self::API.'/prospects', ['X-Portal-Key' => self::KEY])->assertOk(); // LAN client (127.0.0.1): unchanged

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson(self::API.'/prospects', ['X-Portal-Key' => self::KEY])
            ->assertStatus(403)->assertJsonPath('error_code', 'NETWORK_NOT_ALLOWED');

        // The portal surface is reachable from the website host (not LAN) with the key.
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson(self::PORTAL.'/catalog/products', $this->keyHeaders())->assertOk();
    }

    public function test_portal_network_allow_list_can_pin_the_website_server(): void
    {
        config(['crm.portal.allowed_networks' => ['203.0.113.10']]);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson(self::PORTAL.'/catalog/products', $this->keyHeaders())->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson(self::PORTAL.'/catalog/products', $this->keyHeaders())->assertOk();
    }

    public function test_register_login_logout_and_enumeration_safe_errors(): void
    {
        $register = $this->postJson(self::PORTAL.'/auth/register', ['name' => 'Sara', 'email' => 'Sara@Example.test', 'password' => 'long-enough-1'], $this->keyHeaders())
            ->assertCreated()->assertJsonPath('data.account.email', 'sara@example.test');
        $this->assertArrayNotHasKey('password', $register->json('data.account'));

        $this->postJson(self::PORTAL.'/auth/register', ['name' => 'Dup', 'email' => 'sara@example.test', 'password' => 'long-enough-1'], $this->keyHeaders())->assertStatus(422);

        $login = $this->postJson(self::PORTAL.'/auth/login', ['email' => 'sara@example.test', 'password' => 'long-enough-1'], $this->keyHeaders())->assertOk();
        $token = $login->json('data.token');

        $bad = $this->postJson(self::PORTAL.'/auth/login', ['email' => 'sara@example.test', 'password' => 'wrong'], $this->keyHeaders());
        $unknown = $this->postJson(self::PORTAL.'/auth/login', ['email' => 'ghost@example.test', 'password' => 'wrong'], $this->keyHeaders());
        $bad->assertStatus(401);
        $this->assertSame($bad->json('message'), $unknown->json('message'));

        $headers = $this->keyHeaders() + ['Authorization' => 'Bearer '.$token];
        $this->getJson(self::PORTAL.'/me', $headers)->assertOk();
        $this->postJson(self::PORTAL.'/auth/logout', [], $headers)->assertOk();
        $this->getJson(self::PORTAL.'/me', $headers)->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        config(['crm.portal.login_attempts_per_minute' => 3]);
        // The limiter is read when the app boots, so rebuild it for this test.
        RateLimiter::for('portal-login', fn ($r) => Limit::perMinute(3)->by($r->ip()));

        foreach (range(1, 3) as $_) {
            $this->postJson(self::PORTAL.'/auth/login', ['email' => 'x@example.test', 'password' => 'wrong'], $this->keyHeaders())->assertStatus(401);
        }
        $this->postJson(self::PORTAL.'/auth/login', ['email' => 'x@example.test', 'password' => 'wrong'], $this->keyHeaders())->assertStatus(429);
    }

    public function test_password_reset_flow_is_single_use_and_signs_out_other_sessions(): void
    {
        [$account, $headers] = $this->signedIn();

        $unknown = $this->postJson(self::PORTAL.'/auth/forgot-password', ['email' => 'ghost@example.test'], $this->keyHeaders())->assertOk();
        $this->assertNull($unknown->json('data.reset_token'));

        $token = $this->postJson(self::PORTAL.'/auth/forgot-password', ['email' => $account->email], $this->keyHeaders())->json('data.reset_token');
        $this->assertSame(64, strlen($token));

        $this->postJson(self::PORTAL.'/auth/reset-password', ['token' => $token, 'password' => 'brand-new-pass'], $this->keyHeaders())->assertOk();
        $this->postJson(self::PORTAL.'/auth/reset-password', ['token' => $token, 'password' => 'again-again-1'], $this->keyHeaders())->assertStatus(422);
        $this->getJson(self::PORTAL.'/me', $headers)->assertStatus(401);
        $this->postJson(self::PORTAL.'/auth/login', ['email' => $account->email, 'password' => 'brand-new-pass'], $this->keyHeaders())->assertOk();
    }

    public function test_expired_reset_token_and_expired_session_token_are_rejected(): void
    {
        [$account, $headers] = $this->signedIn();
        $reset = $this->postJson(self::PORTAL.'/auth/forgot-password', ['email' => $account->email], $this->keyHeaders())->json('data.reset_token');

        $this->travel(2)->hours();
        $this->postJson(self::PORTAL.'/auth/reset-password', ['token' => $reset, 'password' => 'brand-new-pass'], $this->keyHeaders())->assertStatus(422);

        $this->travel(31)->days();
        $this->getJson(self::PORTAL.'/me', $headers)->assertStatus(401);
    }

    public function test_deactivated_accounts_cannot_use_existing_tokens(): void
    {
        [$account, $headers] = $this->signedIn();
        $account->update(['is_active' => false]);

        $this->getJson(self::PORTAL.'/me', $headers)->assertStatus(401);
        $this->assertSame(1, PortalAccount::count());
        $this->assertSame(0, Product::count());
    }
}
