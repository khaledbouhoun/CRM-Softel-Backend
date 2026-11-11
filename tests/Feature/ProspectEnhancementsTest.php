<?php

namespace Tests\Feature;

use App\Models\Prospect;
use Tests\TestCase;

/** New profile fields and the unified prospect search. */
class ProspectEnhancementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPipeline();
    }

    public function test_it_stores_and_returns_the_new_profile_fields(): void
    {
        $id = $this->postJson(self::API.'/prospects', [
            'name' => 'Alpha SARL',
            'business_activity' => 'wholesale',
            'source' => 'other',
            'source_other' => 'Trade fair',
            'previous_software' => 'sage',
        ])
            ->assertCreated()
            ->assertJsonPath('data.business_activity', 'wholesale')
            ->assertJsonPath('data.source', 'other')
            ->assertJsonPath('data.source_other', 'Trade fair')
            ->assertJsonPath('data.previous_software', 'sage')
            ->json('data.id');

        $this->assertDatabaseHas('prospects', ['id' => $id, 'source' => 'other', 'previous_software' => 'sage']);

        $this->putJson(self::API."/prospects/{$id}", ['source' => 'facebook'])
            ->assertOk()
            ->assertJsonPath('data.source', 'facebook')
            // source_other only applies to "other".
            ->assertJsonPath('data.source_other', null)
            // Untouched fields are kept.
            ->assertJsonPath('data.business_activity', 'wholesale')
            ->assertJsonPath('data.previous_software', 'sage');
    }

    public function test_existing_prospects_without_the_new_fields_still_work(): void
    {
        $prospect = Prospect::factory()->inStage($this->newStage)->create();

        $this->getJson(self::API."/prospects/{$prospect->id}")
            ->assertOk()
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.business_activity', null);

        $this->putJson(self::API."/prospects/{$prospect->id}", ['city' => 'Oran'])
            ->assertOk()
            ->assertJsonPath('data.city', 'Oran');
    }

    public function test_source_must_be_a_code_and_can_be_cleared(): void
    {
        $this->postJson(self::API.'/prospects', ['name' => 'X', 'source' => 'Face Book'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('source', 'errors');

        $id = $this->postJson(self::API.'/prospects', ['name' => 'X', 'source' => 'tiktok'])
            ->assertCreated()->json('data.id');

        $this->putJson(self::API."/prospects/{$id}", ['source' => null, 'previous_software' => '  '])
            ->assertOk()
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.previous_software', null);
    }

    public function test_search_matches_name_company_phone_and_id_across_all_stages(): void
    {
        $lost = Prospect::factory()->inStage($this->lostStage)->create([
            'name' => 'Zeta Commerce', 'contact_person' => 'Karim Benali', 'phone' => '+213 555 12 34 56',
        ]);
        $won = Prospect::factory()->inStage($this->wonStage)->create([
            'name' => 'Omega Trading', 'contact_person' => 'Sara Haddad', 'phone' => '0661 77 88 99',
        ]);
        $open = Prospect::factory()->inStage($this->newStage)->create([
            'name' => 'Delta SARL', 'contact_person' => 'Nadir Amrani', 'phone' => '0770000000',
        ]);

        $ids = fn (array $query) => collect($this->getJson(self::API.'/prospects?'.http_build_query($query))
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$lost->id], $ids(['search' => 'benali']));                 // contact person (full name)
        $this->assertSame([$won->id], $ids(['search' => 'omega']));                   // company
        $this->assertSame([$lost->id], $ids(['search' => '555 12']));                 // phone with spaces
        $this->assertSame([$lost->id], $ids(['search' => '55512']));                  // phone digits only
        $this->assertSame([$lost->id], $ids(['search' => '(213) 555']));              // phone with other formatting
        $this->assertSame([$won->id], $ids(['search' => '0661']));                    // partial phone
        // A short number also matches phone digits, so check inclusion rather than equality.
        $this->assertContains($open->id, $ids(['search' => (string) $open->id]));     // prospect id
        $this->assertSame([], $ids(['search' => 'nothing-matches']));

        // Won / lost prospects carry their stage in the result.
        $this->getJson(self::API.'/prospects?search=benali')
            ->assertJsonPath('data.0.sales_stage.stage_type', 'lost')
            ->assertJsonPath('data.0.sales_stage.name', 'Lost');
    }

    public function test_search_can_be_combined_with_filters_and_new_field_filters(): void
    {
        Prospect::factory()->inStage($this->newStage)->create(['name' => 'Acme One', 'source' => 'facebook']);
        $match = Prospect::factory()->inStage($this->newStage)->create(['name' => 'Acme Two', 'source' => 'website']);
        Prospect::factory()->inStage($this->demoStage)->create(['name' => 'Acme Three', 'source' => 'website']);

        $response = $this->getJson(self::API.'/prospects?'.http_build_query([
            'search' => 'acme', 'source' => 'website', 'sales_stage_id' => $this->newStage->id,
        ]))->assertOk();

        $this->assertSame([$match->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_lookups_expose_the_new_catalogues(): void
    {
        $this->getJson(self::API.'/lookups')
            ->assertOk()
            ->assertJsonPath('data.follow_up_activity_types', ['call', 'meeting', 'message', 'other'])
            ->assertJsonPath('data.prospect_sources.0', 'facebook')
            ->assertJsonPath('data.business_activities.0', 'wholesale')
            ->assertJsonPath('data.previous_software.0', 'sage');
    }
}
