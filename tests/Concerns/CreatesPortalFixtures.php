<?php

namespace Tests\Concerns;

use App\Models\PortalAccount;
use App\Models\Product;
use App\Models\Prospect;
use App\Services\PortalAuthService;
use Database\Seeders\PortalDefaultsSeeder;

trait CreatesPortalFixtures
{
    protected const PORTAL = '/api/portal/v1';

    protected const KEY = 'test-service-key';

    /** Monday 08:00 Algiers. Default working week is Sunday-Thursday, 09:00-16:00, 1h slots, 12h notice. */
    protected function setUpPortal(): void
    {
        config(['crm.portal.service_key' => self::KEY]);
        $this->createPipeline();
        $this->seed(PortalDefaultsSeeder::class);
        $this->freezeAt('2026-11-16 08:00:00');
    }

    /** @return array<string, string> */
    protected function keyHeaders(): array
    {
        return ['X-Portal-Key' => self::KEY];
    }

    /** @return array{0: PortalAccount, 1: array<string, string>} */
    protected function signedIn(?Prospect $prospect = null, string $email = 'ali@example.test'): array
    {
        $account = PortalAccount::query()->create([
            'name' => 'Ali', 'email' => $email, 'password' => 'secret-pass-1', 'prospect_id' => $prospect?->id,
        ]);
        $token = app(PortalAuthService::class)->issueToken($account);

        return [$account, $this->keyHeaders() + ['Authorization' => 'Bearer '.$token]];
    }

    /** A converted customer that bought $product, with a signed-in portal account. @return array{0: Prospect, 1: PortalAccount, 2: array<string, string>} */
    protected function customerWith(Product $product, string $email = 'ali@example.test'): array
    {
        $prospect = Prospect::factory()->inStage($this->wonStage)->create();
        $prospect->products()->attach($product->id, ['purchased_at' => now()]);
        $prospect->forceFill(['converted_at' => now()])->save();
        [$account, $headers] = $this->signedIn($prospect, $email);

        return [$prospect, $account, $headers];
    }

    /** @return array<string, mixed> */
    protected function requestPayload(Product $product, ?string $slot = '2026-11-17 10:00:00', string $type = 'demo'): array
    {
        return [
            'type' => $type, 'product_id' => $product->id, 'company' => 'Atlas Trading', 'contact_name' => 'Karim B.',
            'job_title' => 'Manager', 'phone' => '0555123456', 'email' => 'karim@atlas.test', 'wilaya' => 'Alger',
            'city' => 'Bab Ezzouar', 'business_activity' => 'wholesale', 'notes' => 'We need stock management.',
            'slot_start' => $slot,
        ];
    }
}
