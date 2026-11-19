<?php

namespace Tests\Feature;

use App\Models\ProductRelease;
use App\Models\Product;
use App\Models\Prospect;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class ProductReleaseTest extends TestCase
{
    use CreatesPortalFixtures;

    private Product $erp;

    private Product $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
        Storage::fake('local');
        $this->erp = Product::factory()->create(['name' => 'ERP']);
        $this->pos = Product::factory()->create(['name' => 'POS']);
    }

    private function release(array $over = [])
    {
        return $this->post(self::API.'/product-releases', $over + [
            'product_id' => $this->erp->id, 'version_number' => '4.2.1', 'changelog' => 'Rounding fixed', 'release_date' => '2026-11-10', 'audience' => 'all',
        ], ['Accept' => 'application/json', 'X-Employee-Name' => 'Samir']);
    }

    private function ids(array $headers): array
    {
        return collect($this->getJson(self::PORTAL.'/releases', $headers)->assertOk()->json('data'))->pluck('version_number')->sort()->values()->all();
    }

    public function test_staff_create_a_release_with_an_installer(): void
    {
        $response = $this->release(['installer' => UploadedFile::fake()->create('erp-setup.exe', 500, 'application/x-msdownload'), 'download_url' => 'https://cdn.example.test/erp'])->assertCreated();

        $response->assertJsonPath('data.version_number', '4.2.1')->assertJsonPath('data.installer.name', 'erp-setup.exe')->assertJsonPath('data.created_by', 'Samir');
        $path = ProductRelease::first()->installer_path;
        Storage::disk('local')->assertExists($path);
        $this->get(self::API.'/product-releases/'.ProductRelease::first()->id.'/download')->assertOk();
    }

    public function test_release_validation(): void
    {
        $this->release()->assertCreated();
        $this->release()->assertStatus(422)->assertJsonValidationErrors('version_number');                                   // same version twice
        $this->release(['version_number' => '4.3.0'])->assertCreated();
        $this->release(['product_id' => $this->pos->id])->assertCreated();                                                 // same number, other product
        $this->release(['version_number' => '5', 'installer' => UploadedFile::fake()->create('x.php', 1)])->assertStatus(422)->assertJsonValidationErrors('installer');
        $this->release(['version_number' => '5', 'audience' => 'nobody'])->assertStatus(422)->assertJsonValidationErrors('audience');
        $this->release(['version_number' => '5', 'audience' => 'specific'])->assertStatus(422)->assertJsonValidationErrors('customer_ids');
        $this->release(['version_number' => '5', 'download_url' => 'javascript:alert(1)'])->assertStatus(422);
    }

    public function test_update_replace_and_delete_manage_the_installer_file(): void
    {
        $id = $this->release(['installer' => UploadedFile::fake()->create('one.exe', 10, 'application/x-msdownload')])->json('data.id');
        $first = ProductRelease::find($id)->installer_path;

        $this->putJson(self::API."/product-releases/{$id}", ['changelog' => 'Updated notes', 'is_published' => false])->assertOk()->assertJsonPath('data.changelog', 'Updated notes');

        $this->post(self::API."/product-releases/{$id}/installer", ['installer' => UploadedFile::fake()->create('two.zip', 10, 'application/zip')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.installer.name', 'two.zip');
        Storage::disk('local')->assertMissing($first);
        $second = ProductRelease::find($id)->installer_path;

        $this->deleteJson(self::API."/product-releases/{$id}/installer")->assertOk()->assertJsonPath('data.installer', null);
        Storage::disk('local')->assertMissing($second);

        $this->deleteJson(self::API."/product-releases/{$id}")->assertOk();
        $this->assertSame(0, ProductRelease::count());
    }

    public function test_staff_list_filters(): void
    {
        $this->release()->assertCreated();
        $this->release(['product_id' => $this->pos->id, 'audience' => 'owned'])->assertCreated();

        $this->assertCount(2, $this->getJson(self::API.'/product-releases')->json('data'));
        $this->assertCount(1, $this->getJson(self::API."/product-releases?product_id={$this->pos->id}")->json('data'));
        $this->assertCount(1, $this->getJson(self::API.'/product-releases?audience=all')->json('data'));
    }

    public function test_audience_all_reaches_every_customer_but_not_prospects(): void
    {
        $this->release()->assertCreated();
        [, , $ownerOfErp] = $this->customerWith($this->erp, 'a@example.test');
        [, , $ownerOfPos] = $this->customerWith($this->pos, 'b@example.test');
        $lead = Prospect::factory()->inStage($this->demoStage)->create();
        [, $leadHeaders] = $this->signedIn($lead, 'lead@example.test');

        $this->assertSame(['4.2.1'], $this->ids($ownerOfErp));
        $this->assertSame(['4.2.1'], $this->ids($ownerOfPos));
        $this->assertSame([], $this->ids($leadHeaders));
    }

    public function test_audience_owned_reaches_only_owners_of_the_product(): void
    {
        $this->release(['audience' => 'owned'])->assertCreated();
        [, , $owner] = $this->customerWith($this->erp, 'a@example.test');
        [, , $other] = $this->customerWith($this->pos, 'b@example.test');
        $interested = Prospect::factory()->inStage($this->wonStage)->create();
        $interested->products()->attach($this->erp->id);   // interested, not purchased
        $interested->forceFill(['converted_at' => now()])->save();
        [, $interestedHeaders] = $this->signedIn($interested, 'c@example.test');

        $this->assertSame(['4.2.1'], $this->ids($owner));
        $this->assertSame([], $this->ids($other));
        $this->assertSame([], $this->ids($interestedHeaders));
    }

    public function test_audience_specific_reaches_only_the_named_customers_and_can_change(): void
    {
        [$a, , $headersA] = $this->customerWith($this->erp, 'a@example.test');
        [, , $headersB] = $this->customerWith($this->erp, 'b@example.test');
        $id = $this->release(['audience' => 'specific', 'customer_ids' => [$a->id]])->assertCreated()->assertJsonPath('data.customer_ids', [$a->id])->json('data.id');

        $this->assertSame(['4.2.1'], $this->ids($headersA));
        $this->assertSame([], $this->ids($headersB));

        $this->putJson(self::API."/product-releases/{$id}", ['audience' => 'all'])->assertOk()->assertJsonPath('data.customer_ids', []);
        $this->assertSame(['4.2.1'], $this->ids($headersB));
    }

    public function test_drafts_and_future_releases_stay_hidden(): void
    {
        [, , $headers] = $this->customerWith($this->erp);
        $this->release(['version_number' => '1.0', 'is_published' => false])->assertCreated();
        $this->release(['version_number' => '2.0', 'release_date' => '2026-12-01'])->assertCreated();
        $this->release(['version_number' => '3.0'])->assertCreated();

        $this->assertSame(['3.0'], $this->ids($headers));
    }

    public function test_installer_download_is_limited_to_the_audience(): void
    {
        $this->release(['audience' => 'owned', 'installer' => UploadedFile::fake()->create('erp.exe', 10, 'application/x-msdownload')])->assertCreated();
        $id = ProductRelease::first()->id;
        [, , $owner] = $this->customerWith($this->erp, 'a@example.test');
        [, , $other] = $this->customerWith($this->pos, 'b@example.test');

        $listed = $this->getJson(self::PORTAL.'/releases', $owner)->json('data.0');
        $this->assertSame("/api/portal/v1/releases/{$id}/download", $listed['installer']['download_url']);
        $this->assertStringNotContainsString('installer_path', json_encode($listed));

        $this->get(self::PORTAL."/releases/{$id}/download", $owner)->assertOk();
        $this->get(self::PORTAL."/releases/{$id}/download", $other)->assertStatus(404);
        $this->getJson(self::PORTAL."/releases/{$id}", $other)->assertStatus(404);
        $this->getJson(self::PORTAL.'/releases')->assertStatus(401);   // no customer token
    }
}
