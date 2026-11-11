<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\ProspectFormOption;
use Tests\TestCase;

class ProspectFormOptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
    }

    public function test_can_create_update_and_list_options(): void
    {
        $response = $this->postJson(self::API.'/prospect-form-options', [
            'type' => 'profession',
            'name' => 'Automotive & Cars',
            'requires_details' => true,
        ])->assertCreated();

        $id = $response->json('data.id');
        $this->assertSame('Automotive & Cars', $response->json('data.name'));
        $this->assertTrue($response->json('data.requires_details'));
        $this->assertTrue($response->json('data.is_active'));

        $this->putJson(self::API."/prospect-form-options/{$id}", [
            'name' => 'Automotive',
            'requires_details' => false,
        ])->assertOk()
          ->assertJsonPath('data.name', 'Automotive')
          ->assertJsonPath('data.requires_details', false);

        $this->getJson(self::API.'/prospect-form-options?type=profession')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_reorder_options_by_type(): void
    {
        $opt1 = ProspectFormOption::create(['type' => 'source', 'name' => 'Source 1', 'sort_order' => 1]);
        $opt2 = ProspectFormOption::create(['type' => 'source', 'name' => 'Source 2', 'sort_order' => 2]);

        $this->postJson(self::API.'/prospect-form-options/reorder', [
            'type' => 'source',
            'ids' => [$opt2->id, $opt1->id],
        ])->assertOk();

        $this->assertSame(1, $opt2->fresh()->sort_order);
        $this->assertSame(2, $opt1->fresh()->sort_order);
    }

    public function test_used_option_cannot_be_deleted(): void
    {
        $option = ProspectFormOption::create([
            'type' => 'source',
            'name' => 'Trade fair',
            'requires_details' => false,
        ]);

        Prospect::factory()->inStage($this->newStage)->create([
            'source' => 'Trade fair',
        ]);

        $this->deleteJson(self::API."/prospect-form-options/{$option->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        $this->assertDatabaseHas('prospect_form_options', ['id' => $option->id]);

        // Can deactivate it instead
        $this->putJson(self::API."/prospect-form-options/{$option->id}", [
            'name' => 'Trade fair',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_unused_option_can_be_deleted(): void
    {
        $option = ProspectFormOption::create([
            'type' => 'profession',
            'name' => 'Temporary',
        ]);

        $this->deleteJson(self::API."/prospect-form-options/{$option->id}")
            ->assertOk();

        $this->assertDatabaseMissing('prospect_form_options', ['id' => $option->id]);
    }

    public function test_requires_details_is_enforced(): void
    {
        ProspectFormOption::create([
            'type' => 'source',
            'name' => 'Facebook',
            'requires_details' => true,
        ]);

        // Attempting to select Facebook without source_details fails
        $this->postJson(self::API.'/prospects', [
            'name' => 'Client Without Details',
            'source' => 'Facebook',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['source_details']);

        // With source_details succeeds
        $res = $this->postJson(self::API.'/prospects', [
            'name' => 'Client With Details',
            'source' => 'Facebook',
            'source_details' => 'Softel Main Page',
        ])->assertCreated();

        $res->assertJsonPath('data.source', 'Facebook')
            ->assertJsonPath('data.source_details', 'Softel Main Page');
    }

    public function test_profession_and_details_stored_and_retrieved(): void
    {
        ProspectFormOption::create([
            'type' => 'profession',
            'name' => 'Electronics',
            'requires_details' => true,
        ]);

        $res = $this->postJson(self::API.'/prospects', [
            'name' => 'Electro Corp',
            'profession' => 'Electronics',
            'profession_details' => 'Smartphones & Accessories',
        ])->assertCreated();

        $res->assertJsonPath('data.profession', 'Electronics')
            ->assertJsonPath('data.profession_details', 'Smartphones & Accessories');
    }
}
