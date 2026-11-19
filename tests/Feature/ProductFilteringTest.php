<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Prospect;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class ProductFilteringTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $erp;

    private Product $pos;

    private Product $crm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        $this->erp = Product::factory()->create(['name' => 'ERP', 'demo_url' => 'https://demo.example.test/erp']);
        $this->pos = Product::factory()->create(['name' => 'POS']);
        $this->crm = Product::factory()->create(['name' => 'CRM']);
    }

    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_products_can_be_limited_to_those_a_prospect_is_interested_in_or_owns(): void
    {
        $prospect = Prospect::factory()->inStage($this->demoStage)->create();
        $prospect->products()->attach($this->erp->id);
        $prospect->products()->attach($this->pos->id, ['purchased_at' => now()]);

        $this->assertSame([$this->erp->id, $this->pos->id], $this->ids(self::API."/products?prospect_id={$prospect->id}"));
        $this->assertSame([$this->erp->id], $this->ids(self::API."/products?prospect_id={$prospect->id}&relation=interested"));
        $this->assertSame([$this->pos->id], $this->ids(self::API."/products?prospect_id={$prospect->id}&relation=owned"));
    }

    public function test_unknown_relation_or_prospect_is_rejected(): void
    {
        $this->getJson(self::API.'/products?prospect_id=9999')->assertStatus(422);
        $this->getJson(self::API.'/products?relation=everything')->assertStatus(422);
    }

    public function test_demo_list_only_has_products_with_a_demo_link(): void
    {
        $prospect = Prospect::factory()->inStage($this->demoStage)->create();
        $prospect->products()->attach([$this->erp->id, $this->pos->id]);

        $this->assertSame([$this->erp->id], $this->ids(self::API."/products?prospect_id={$prospect->id}&has_demo=1"));
    }

    public function test_prospects_can_be_limited_to_those_linked_to_a_product(): void
    {
        $interested = Prospect::factory()->inStage($this->demoStage)->create();
        $owner = Prospect::factory()->inStage($this->wonStage)->create();
        $other = Prospect::factory()->inStage($this->demoStage)->create();
        $interested->products()->attach($this->erp->id);
        $owner->products()->attach($this->erp->id, ['purchased_at' => now()]);
        $other->products()->attach($this->pos->id);

        $list = fn (string $q) => collect($this->getJson(self::API."/prospects?product_id={$this->erp->id}{$q}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$interested->id, $owner->id], $list(''));
        $this->assertSame([$interested->id], $list('&product_relation=interested'));
        $this->assertSame([$owner->id], $list('&product_relation=owned'));
    }

    public function test_customers_can_be_filtered_by_a_product_they_own(): void
    {
        [$mine] = $this->customerWith($this->erp, 'a@example.test');
        [$theirs] = $this->customerWith($this->pos, 'b@example.test');

        $ids = collect($this->getJson(self::API."/customers?product_id={$this->erp->id}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }
}
