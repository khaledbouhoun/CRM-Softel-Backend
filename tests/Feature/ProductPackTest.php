<?php

namespace Tests\Feature;

use App\Models\Pack;
use App\Models\Product;
use App\Rules\OneProductPerPack;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProductPackTest extends TestCase
{
    public function test_product_stores_website_fields_and_demo_url(): void
    {
        $pack = Pack::query()->create(['name' => 'GestCom', 'code' => 'gestcom']);

        $this->postJson(self::API.'/products', [
            'name' => 'GestCom Basic', 'pack_id' => $pack->id, 'demo_url' => 'https://demo.example.test/basic',
            'features' => ['Invoices', 'Stock'], 'is_active_on_web' => true,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'gestcom-basic')
            ->assertJsonPath('data.pack_id', $pack->id)
            ->assertJsonPath('data.demo_url', 'https://demo.example.test/basic');
    }

    public function test_demo_url_must_be_a_valid_url(): void
    {
        $this->postJson(self::API.'/products', ['name' => 'X', 'demo_url' => 'not a url'])->assertStatus(422);
    }

    public function test_two_products_of_the_same_pack_are_rejected(): void
    {
        $pack = Pack::query()->create(['name' => 'GestCom', 'code' => 'gestcom']);
        $basic = Product::factory()->create(['pack_id' => $pack->id]);
        $ultra = Product::factory()->create(['pack_id' => $pack->id]);
        $solo = Product::factory()->create();

        $fails = fn (array $ids) => Validator::make(['ids' => $ids], ['ids' => [new OneProductPerPack]])->fails();

        $this->assertTrue($fails([$basic->id, $ultra->id]));
        $this->assertFalse($fails([$basic->id, $solo->id]));
        $this->assertFalse($fails([$solo->id]));
    }

    public function test_portal_catalogue_only_lists_products_published_on_the_web(): void
    {
        config(['crm.portal.service_key' => 'key']);
        Product::factory()->create(['name' => 'Live', 'is_active_on_web' => true]);
        Product::factory()->create(['name' => 'Hidden', 'is_active_on_web' => false]);

        $this->getJson('/api/portal/v1/catalog/products', ['X-Portal-Key' => 'key'])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Live');
        $this->getJson('/api/portal/v1/catalog/products')->assertStatus(401);
    }
}
