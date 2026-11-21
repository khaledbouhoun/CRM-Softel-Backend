<?php

namespace Tests\Feature;

use App\Models\Pack;
use App\Models\Product;
use App\Models\Prospect;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class PackSelectionTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $basic;

    private Product $ultra;

    private Product $solo;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $pack = Pack::query()->create(['name' => 'GestCom', 'code' => 'gestcom']);
        $this->basic = Product::factory()->create(['name' => 'GestCom Basic', 'pack_id' => $pack->id, 'is_active_on_web' => true, 'demo_url' => 'https://demo.example.test/b']);
        $this->ultra = Product::factory()->create(['name' => 'GestCom Ultra', 'pack_id' => $pack->id, 'is_active_on_web' => true, 'demo_url' => 'https://demo.example.test/u']);
        $this->solo = Product::factory()->create(['name' => 'Solo', 'is_active_on_web' => true]);
        $this->other = Product::factory()->create(['name' => 'Other', 'is_active_on_web' => true]);
    }

    public function test_packs_can_be_managed_and_products_listed_per_pack(): void
    {
        $id = $this->postJson(self::API.'/packs', ['name' => 'Suite', 'code' => 'suite'])->assertCreated()->json('data.id');
        $this->postJson(self::API.'/packs', ['name' => 'Suite', 'code' => 'suite2'])->assertStatus(422);
        $this->putJson(self::API."/packs/{$id}", ['description' => 'All tiers'])->assertOk()->assertJsonPath('data.description', 'All tiers');
        $this->getJson(self::API.'/packs')->assertOk()->assertJsonFragment(['code' => 'gestcom']);
        $this->deleteJson(self::API."/packs/{$id}")->assertOk();
    }

    public function test_prospect_cannot_be_created_or_updated_with_two_tiers_of_one_pack(): void
    {
        $this->postJson(self::API.'/prospects', ['name' => 'Atlas', 'product_ids' => [$this->basic->id, $this->ultra->id]])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');

        $created = $this->postJson(self::API.'/prospects', ['name' => 'Atlas', 'product_ids' => [$this->basic->id, $this->solo->id]])->assertCreated();
        $id = $created->json('data.id');

        $this->putJson(self::API."/prospects/{$id}", ['product_ids' => [$this->basic->id, $this->ultra->id]])->assertStatus(422);
        $this->putJson(self::API."/prospects/{$id}", ['product_ids' => [$this->ultra->id, $this->solo->id]])->assertOk();
    }

    public function test_conversion_rejects_two_tiers_of_one_pack(): void
    {
        $prospect = Prospect::factory()->inStage($this->demoStage)->create();

        $this->postJson(self::API."/prospects/{$prospect->id}/convert", ['product_ids' => [$this->basic->id, $this->ultra->id]])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');
    }

    public function test_trial_activation_accepts_several_products_but_one_per_pack(): void
    {
        $prospect = Prospect::factory()->inStage($this->demoStage)->create();
        $prospect->products()->attach([$this->basic->id, $this->ultra->id]);
        $url = self::API."/prospects/{$prospect->id}/trials";

        $this->postJson($url, ['product_ids' => [$this->basic->id, $this->ultra->id]])->assertStatus(422)->assertJsonValidationErrors('product_ids');
        $this->assertSame(0, $prospect->trials()->count());

        $this->postJson($url, ['product_ids' => [$this->basic->id]])->assertCreated()->assertJsonCount(1, 'data');
        $this->postJson($url, ['product_id' => $this->ultra->id, 'product_ids' => [$this->ultra->id]])->assertStatus(422);
    }

    public function test_portal_request_accepts_several_products_one_per_pack(): void
    {
        [, $headers] = $this->signedIn();
        $payload = $this->requestPayload($this->basic, null, 'quotation');
        unset($payload['product_id']);

        $this->postJson(self::PORTAL.'/requests', $payload + ['product_ids' => [$this->basic->id, $this->ultra->id]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');

        $this->postJson(self::PORTAL.'/requests', $payload + ['product_ids' => [$this->basic->id, $this->solo->id, $this->other->id]], $headers)
            ->assertCreated()->assertJsonCount(3, 'data');
        $this->assertCount(3, $this->getJson(self::PORTAL.'/requests', $headers)->json('data'));
    }

    public function test_portal_request_keeps_working_with_a_single_product_id(): void
    {
        [, $headers] = $this->signedIn();

        $this->postJson(self::PORTAL.'/requests', $this->requestPayload($this->solo, null, 'quotation'), $headers)
            ->assertCreated()->assertJsonPath('data.product.id', $this->solo->id);
    }

    public function test_a_demo_slot_can_be_booked_for_several_products_one_per_pack(): void
    {
        [, $headers] = $this->signedIn();
        $payload = $this->requestPayload($this->solo, '2026-11-18 10:00:00');
        unset($payload['product_id']);

        $this->postJson(self::PORTAL.'/requests', $payload + ['product_ids' => [$this->solo->id, $this->other->id]], $headers)
            ->assertCreated()->assertJsonCount(2, 'data');
    }
}
