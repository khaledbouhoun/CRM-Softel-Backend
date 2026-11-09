<?php

namespace Tests\Feature;

use App\Models\LossReason;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\SalesStage;
use Tests\TestCase;

/** Products, sales stages and loss reasons. */
class ConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
    }

    // --- Products -----------------------------------------------------------

    public function test_product_crud_and_deactivation(): void
    {
        $id = $this->postJson(self::API.'/products', ['name' => 'ERP Commercial'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');

        $this->postJson(self::API.'/products', ['name' => 'ERP Commercial'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->putJson(self::API."/products/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'ERP Commercial');

        $this->getJson(self::API.'/products?is_active=true')->assertJsonPath('meta.total', 0);
        $this->getJson(self::API.'/products?is_active=false')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API."/products/{$id}")->assertOk()->assertJsonPath('data.prospects_count', 0);

        $this->deleteJson(self::API."/products/{$id}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_product_used_by_a_prospect_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $prospect = Prospect::factory()->inStage($this->newStage)->create();
        $prospect->products()->attach($product);
        $prospect->delete(); // even soft-deleted prospects keep the product "in use"

        $this->deleteJson(self::API."/products/{$product->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    // --- Sales stages -------------------------------------------------------

    public function test_stage_create_validate_and_list_in_order(): void
    {
        $this->postJson(self::API.'/sales-stages', ['name' => 'Negotiation', 'stage_type' => 'open', 'color' => '#FFAA00'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.stage_type', 'open');

        $this->postJson(self::API.'/sales-stages', ['name' => 'Bad', 'stage_type' => 'maybe', 'color' => 'red'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stage_type', 'color']);

        $names = collect($this->getJson(self::API.'/sales-stages')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['New', 'Demo', 'Won', 'Lost', 'Negotiation'], $names);
    }

    public function test_reorder_stages(): void
    {
        $ids = [$this->lostStage->id, $this->wonStage->id, $this->demoStage->id, $this->newStage->id];

        $this->postJson(self::API.'/sales-stages/reorder', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->lostStage->id)
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.3.id', $this->newStage->id);

        $this->postJson(self::API.'/sales-stages/reorder', ['ids' => [$this->lostStage->id, $this->wonStage->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['ids']);
    }

    public function test_used_stage_cannot_be_deleted_or_change_type_but_can_be_deactivated(): void
    {
        Prospect::factory()->inStage($this->demoStage)->create();

        $this->deleteJson(self::API."/sales-stages/{$this->demoStage->id}")
            ->assertStatus(409)->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        $this->putJson(self::API."/sales-stages/{$this->demoStage->id}", ['stage_type' => 'won'])
            ->assertStatus(422)->assertJsonPath('error_code', 'STAGE_TYPE_LOCKED');

        $this->putJson(self::API."/sales-stages/{$this->demoStage->id}", ['is_active' => false, 'name' => 'Demo (old)'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Demo (old)');

        $unused = SalesStage::factory()->open()->create();
        $this->deleteJson(self::API."/sales-stages/{$unused->id}")->assertOk();
    }

    public function test_last_active_open_stage_is_protected(): void
    {
        $this->putJson(self::API."/sales-stages/{$this->demoStage->id}", ['is_active' => false])->assertOk();

        $this->putJson(self::API."/sales-stages/{$this->newStage->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('error_code', 'LAST_OPEN_STAGE');

        $this->deleteJson(self::API."/sales-stages/{$this->newStage->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'LAST_OPEN_STAGE');
    }

    // --- Loss reasons -------------------------------------------------------

    public function test_loss_reason_crud_reorder_and_protection(): void
    {
        $id = $this->postJson(self::API.'/loss-reasons', ['name' => 'Project postponed', 'requires_note' => true])
            ->assertCreated()
            ->assertJsonPath('data.requires_note', true)
            ->assertJsonPath('data.sort_order', 3)
            ->json('data.id');

        $this->putJson(self::API."/loss-reasons/{$id}", ['requires_note' => false])->assertOk()->assertJsonPath('data.requires_note', false);

        $this->postJson(self::API.'/loss-reasons/reorder', ['ids' => [$id, $this->otherReason->id, $this->priceReason->id]])
            ->assertOk()->assertJsonPath('data.0.id', $id);

        $this->deleteJson(self::API."/loss-reasons/{$id}")->assertOk();

        $prospectId = $this->postJson(self::API.'/prospects', [
            'name' => 'Lost Co', 'sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id,
        ])->json('data.id');

        $this->deleteJson(self::API."/loss-reasons/{$this->priceReason->id}")
            ->assertStatus(409)->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        $this->putJson(self::API."/loss-reasons/{$this->priceReason->id}", ['is_active' => false])->assertOk();
        $this->getJson(self::API.'/loss-reasons?is_active=1')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API."/prospects/{$prospectId}")->assertJsonPath('data.loss_reason.name', 'Too expensive');
        $this->assertTrue(LossReason::find($this->priceReason->id)->isInUse());
    }
}
