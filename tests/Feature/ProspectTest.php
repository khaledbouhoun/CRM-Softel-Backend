<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use RuntimeException;
use Tests\TestCase;

class ProspectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
    }

    public function test_it_creates_a_prospect_with_multiple_products_and_initial_history(): void
    {
        [$erp, $pos] = Product::factory()->count(2)->create();

        $response = $this->postJson(self::API.'/prospects', [
            'name' => 'Alpha SARL',
            'contact_person' => 'Karim B.',
            'phone' => '+213 555 12 34 56',
            'email' => 'contact@alpha.dz',
            'city' => 'Alger',
            'sales_stage_id' => $this->demoStage->id,
            'product_ids' => [$erp->id, $pos->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Prospect created successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.name', 'Alpha SARL')
            ->assertJsonPath('data.sales_stage.id', $this->demoStage->id)
            ->assertJsonPath('data.sales_stage.stage_type', 'open')
            ->assertJsonCount(2, 'data.products');

        $id = $response->json('data.id');
        $this->assertDatabaseCount('prospect_products', 2);
        $this->assertDatabaseHas('prospect_stage_histories', [
            'prospect_id' => $id, 'from_stage_id' => null, 'to_stage_id' => $this->demoStage->id,
        ]);
    }

    public function test_it_defaults_to_the_first_active_open_stage(): void
    {
        $this->newStage->update(['is_active' => false]);

        $this->postJson(self::API.'/prospects', ['name' => 'Beta'])
            ->assertCreated()
            ->assertJsonPath('data.sales_stage_id', $this->demoStage->id);
    }

    public function test_creation_validates_fields(): void
    {
        $this->postJson(self::API.'/prospects', ['email' => 'not-an-email', 'phone' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['name', 'email', 'phone']);
    }

    public function test_creation_rejects_an_inactive_stage(): void
    {
        $this->demoStage->update(['is_active' => false]);

        $this->postJson(self::API.'/prospects', ['name' => 'Gamma', 'sales_stage_id' => $this->demoStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sales_stage_id']);

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_creation_rejects_inactive_and_duplicate_products(): void
    {
        $active = Product::factory()->create();
        $inactive = Product::factory()->inactive()->create();

        $this->postJson(self::API.'/prospects', ['name' => 'Delta', 'product_ids' => [$active->id, $inactive->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids.1']);

        $this->postJson(self::API.'/prospects', ['name' => 'Delta', 'product_ids' => [$active->id, $active->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids.0']);

        $this->postJson(self::API.'/prospects', ['name' => 'Delta', 'product_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids.0']);

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_creating_directly_in_a_lost_stage_requires_a_loss_reason(): void
    {
        $this->postJson(self::API.'/prospects', ['name' => 'Eps', 'sales_stage_id' => $this->lostStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['loss_reason_id']);

        $this->postJson(self::API.'/prospects', [
            'name' => 'Eps', 'sales_stage_id' => $this->lostStage->id, 'loss_reason_id' => $this->priceReason->id,
        ])->assertCreated()->assertJsonPath('data.loss_reason.id', $this->priceReason->id);
    }

    public function test_creation_is_rolled_back_when_a_later_step_fails(): void
    {
        $product = Product::factory()->create();

        ProspectStageHistory::creating(fn () => throw new RuntimeException('simulated failure'));

        $this->postJson(self::API.'/prospects', ['name' => 'Rollback Co', 'product_ids' => [$product->id]])
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'SERVER_ERROR')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');

        $this->assertDatabaseCount('prospects', 0);
        $this->assertDatabaseCount('prospect_products', 0);
    }

    public function test_it_updates_fields_and_synchronises_products(): void
    {
        [$a, $b, $c] = Product::factory()->count(3)->create();
        $id = $this->postJson(self::API.'/prospects', ['name' => 'Zeta', 'product_ids' => [$a->id, $b->id]])->json('data.id');

        $this->putJson(self::API."/prospects/{$id}", [
            'name' => 'Zeta Industries',
            'city' => 'Oran',
            'product_ids' => [$b->id, $c->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Zeta Industries')
            ->assertJsonPath('data.city', 'Oran');

        $linked = Prospect::find($id)->products()->pluck('products.id')->sort()->values()->all();
        $this->assertSame([$b->id, $c->id], $linked);
        $this->assertDatabaseCount('prospect_products', 2);
    }

    public function test_update_keeps_an_existing_product_that_was_deactivated_but_rejects_new_inactive_ones(): void
    {
        [$kept, $newInactive] = Product::factory()->count(2)->create();
        $id = $this->postJson(self::API.'/prospects', ['name' => 'Eta', 'product_ids' => [$kept->id]])->json('data.id');
        $kept->update(['is_active' => false]);
        $newInactive->update(['is_active' => false]);

        $this->putJson(self::API."/prospects/{$id}", ['product_ids' => [$kept->id]])->assertOk();

        $this->putJson(self::API."/prospects/{$id}", ['product_ids' => [$kept->id, $newInactive->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids.1']);
    }

    public function test_update_cannot_change_the_stage(): void
    {
        $id = $this->postJson(self::API.'/prospects', ['name' => 'Theta'])->json('data.id');

        // Echoing the current stage back is accepted...
        $this->putJson(self::API."/prospects/{$id}", ['name' => 'Theta 2', 'sales_stage_id' => $this->newStage->id])
            ->assertOk();

        // ...changing it is not.
        $this->putJson(self::API."/prospects/{$id}", ['sales_stage_id' => $this->wonStage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sales_stage_id']);
    }

    public function test_show_returns_404_in_the_standard_format(): void
    {
        $this->getJson(self::API.'/prospects/999999')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Prospect not found.',
                'error_code' => 'NOT_FOUND',
                'errors' => null,
            ]);
    }

    public function test_delete_is_soft_and_keeps_history(): void
    {
        $id = $this->postJson(self::API.'/prospects', ['name' => 'Iota'])->json('data.id');
        $this->postJson(self::API."/prospects/{$id}/activities", ['type' => 'call', 'description' => 'First call']);

        $this->deleteJson(self::API."/prospects/{$id}")->assertOk()->assertJsonPath('data', null);

        $this->assertSoftDeleted('prospects', ['id' => $id]);
        $this->assertDatabaseHas('activities', ['prospect_id' => $id]);
        $this->assertDatabaseHas('prospect_stage_histories', ['prospect_id' => $id]);
        $this->getJson(self::API."/prospects/{$id}")->assertNotFound();
    }

    public function test_list_supports_search_filters_sorting_and_pagination(): void
    {
        $erp = Product::factory()->create();
        Prospect::factory()->inStage($this->newStage)->create(['name' => 'Boulangerie Amine', 'city' => 'Blida']);
        $target = Prospect::factory()->inStage($this->demoStage)->create(['name' => 'Pharmacie Amine', 'city' => 'Alger']);
        $target->products()->attach($erp);
        Prospect::factory()->inStage($this->wonStage)->create(['name' => 'Garage Nour', 'city' => 'Alger']);
        Prospect::factory()->inStage($this->newStage)->create(['name' => 'Old', 'created_at' => now()->subYear()]);

        $this->getJson(self::API.'/prospects?search=amine&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Boulangerie Amine')
            ->assertJsonPath('meta.total', 2);

        $this->getJson(self::API."/prospects?sales_stage_id={$this->demoStage->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);

        $this->getJson(self::API."/prospects?product_id={$erp->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.products.0.id', $erp->id);

        $this->getJson(self::API.'/prospects?stage_type=won')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/prospects?city=Alger')->assertJsonCount(2, 'data');

        $from = now()->subDay()->format('Y-m-d');
        $this->getJson(self::API."/prospects?created_from={$from}")->assertJsonPath('meta.total', 3);

        $this->getJson(self::API.'/prospects?per_page=2&page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4);

        $this->getJson(self::API.'/prospects?sort=password&per_page=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }
}
