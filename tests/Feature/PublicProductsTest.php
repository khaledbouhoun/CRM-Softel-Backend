<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProductsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/v1/products';

    private function web(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge(['is_active_on_web' => true, 'slug' => 'gestcom'], $attrs));
    }

    public function test_it_needs_no_authentication_and_is_cacheable(): void
    {
        $this->web(['sort_order' => 1]);

        $this->getJson(self::URL)->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.0.slug', 'gestcom')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_only_active_public_products_are_listed_sorted_by_sort_order(): void
    {
        $this->web(['slug' => 'second', 'sort_order' => 2]);
        $this->web(['slug' => 'first', 'sort_order' => 1]);
        Product::factory()->create(['slug' => 'hidden', 'is_active_on_web' => false]);
        $this->web(['slug' => 'retired', 'is_active' => false]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'first')
            ->assertJsonPath('data.1.slug', 'second');
    }

    public function test_texts_are_returned_for_every_language(): void
    {
        $this->web([
            'title_i18n' => ['ar' => 'جيستكوم', 'fr' => 'GESTCOM FR', 'en' => 'GESTCOM EN'],
            'summary_i18n' => ['en' => 'Summary EN'],
            'description_i18n' => ['ar' => 'وصف', 'fr' => 'Desc FR', 'en' => 'Desc EN'],
            'tech_stack_i18n' => ['en' => ['Sales', 'Stock'], 'fr' => ['Ventes', 'Stock']],
        ]);

        $this->getJson(self::URL.'/gestcom')->assertOk()
            ->assertJsonPath('data.title.ar', 'جيستكوم')
            ->assertJsonPath('data.title.fr', 'GESTCOM FR')
            ->assertJsonPath('data.summary.ar', 'Summary EN')   // missing translation falls back to English
            ->assertJsonPath('data.tech_stack.fr.0', 'Ventes')
            ->assertJsonPath('data.tech_stack.ar.0', 'Sales')
            ->assertJsonMissingPath('data.localized');
    }

    public function test_locale_parameter_adds_a_resolved_block(): void
    {
        $this->web(['title_i18n' => ['ar' => 'جيستكوم', 'fr' => 'F', 'en' => 'E']]);

        $this->getJson(self::URL.'?locale=ar')->assertOk()
            ->assertJsonPath('data.0.localized.title', 'جيستكوم')
            ->assertJsonPath('data.0.localized.locale', 'ar');
        $this->getJson(self::URL.'/gestcom?locale=xx')->assertOk()->assertJsonMissingPath('data.localized');
    }

    public function test_products_without_translations_fall_back_to_the_crm_name_and_features(): void
    {
        $this->web(['name' => 'Plain product', 'description' => 'Short text', 'features' => ['One', 'Two']]);

        $this->getJson(self::URL.'/gestcom')->assertOk()
            ->assertJsonPath('data.title.fr', 'Plain product')
            ->assertJsonPath('data.summary.en', 'Short text')
            ->assertJsonPath('data.tech_stack.ar', ['One', 'Two']);
    }

    public function test_image_urls_are_absolute(): void
    {
        $this->web(['image_path' => 'https://cdn.example.com/g.png', 'gallery' => ['products/a.png']]);

        $this->getJson(self::URL.'/gestcom')->assertOk()
            ->assertJsonPath('data.image_url', 'https://cdn.example.com/g.png')
            ->assertJsonPath('data.gallery.0', fn ($url) => str_starts_with($url, 'http') && str_ends_with($url, 'products/a.png'));
    }

    public function test_unknown_or_hidden_slug_is_a_404(): void
    {
        Product::factory()->create(['slug' => 'hidden', 'is_active_on_web' => false]);

        $this->getJson(self::URL.'/hidden')->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        $this->getJson(self::URL.'/nope')->assertNotFound();
    }

    public function test_it_is_read_only(): void
    {
        $this->postJson(self::URL, [])->assertStatus(405);
    }

    public function test_staff_edit_multilingual_texts_and_the_public_api_reflects_them(): void
    {
        $id = $this->postJson('/api/v1/products', [
            'name' => 'GESTCOM Paie', 'slug' => 'gestcom-paie', 'is_active_on_web' => true, 'sort_order' => 4,
            'title_i18n' => ['ar' => 'جيستكوم الأجور', 'fr' => 'GESTCOM Paie', 'en' => 'GESTCOM Payroll'],
            'tech_stack_i18n' => ['en' => ['Payroll'], 'fr' => ['Paie'], 'ar' => ['الأجور']],
        ])->assertCreated()->json('data.id');

        $this->getJson(self::URL.'/gestcom-paie?locale=fr')->assertOk()
            ->assertJsonPath('data.title.ar', 'جيستكوم الأجور')
            ->assertJsonPath('data.localized.tech_stack.0', 'Paie');

        $this->putJson("/api/v1/products/{$id}", ['title_i18n' => ['en' => 'Payroll v2']])->assertOk();
        $this->getJson(self::URL.'/gestcom-paie')->assertJsonPath('data.title.en', 'Payroll v2');

        $this->putJson("/api/v1/products/{$id}", ['title_i18n' => ['de' => 'x']])->assertStatus(422);
    }
}
