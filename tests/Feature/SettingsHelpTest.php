<?php

namespace Tests\Feature;

use App\Http\Requests\Api\V1\SchedulingSettingsRequest;
use Tests\TestCase;

class SettingsHelpTest extends TestCase
{
    public function test_help_is_served_for_all_sections_and_one_section(): void
    {
        $this->getJson(self::API.'/settings/help')->assertOk()
            ->assertJsonStructure(['data' => ['scheduling' => ['title', 'help', 'fields'], 'products', 'appointment_types', 'message_templates']]);

        $this->getJson(self::API.'/settings/help/scheduling')->assertOk()->assertJsonPath('data.fields.slot_minutes.example', '60');
        $this->getJson(self::API.'/settings/help/nope')->assertNotFound();
    }

    public function test_every_scheduling_setting_has_a_help_text_and_example(): void
    {
        $fields = array_keys(config('crm_help.scheduling.fields'));
        $settings = collect((new SchedulingSettingsRequest)->rules())->keys()->map(fn ($k) => explode('.', $k)[0])->unique()->all();

        $this->assertEqualsCanonicalizing($settings, $fields);

        foreach (config('crm_help') as $section => $help) {
            $this->assertNotEmpty($help['title'] ?? null, $section);
            $this->assertNotEmpty($help['help'] ?? null, $section);
            foreach ($help['fields'] as $name => $field) {
                $this->assertNotEmpty($field['help'] ?? null, "{$section}.{$name} help");
                $this->assertNotEmpty($field['example'] ?? null, "{$section}.{$name} example");
            }
        }
    }

    public function test_demo_capacity_is_gone_from_settings_and_help(): void
    {
        $this->assertArrayNotHasKey('demo_capacity', config('crm_help.scheduling.fields'));
        $this->getJson(self::API.'/settings/scheduling')->assertOk()->assertJsonMissingPath('data.demo_capacity');
    }

    public function test_help_is_served_in_the_requested_language(): void
    {
        $this->getJson(self::API.'/settings/help/scheduling?locale=ar')->assertOk()
            ->assertJsonPath('data.title', 'قواعد جدولة مواعيد Softel')
            ->assertJsonPath('data.fields.slot_minutes.example', '60');

        $this->getJson(self::API.'/settings/help/scheduling', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertOk()
            ->assertJsonPath('data.title', 'Règles de planification');

        $this->getJson(self::API.'/settings/help/scheduling', ['X-Locale' => 'ar'])->assertOk()
            ->assertJsonPath('data.fields.work_start.label', 'وقت الافتتاح');

        // ?locale wins over the headers.
        $this->getJson(self::API.'/settings/help/scheduling?locale=en', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.title', 'Scheduling rules');

        // Unknown languages fall back to English.
        $this->getJson(self::API.'/settings/help/scheduling?locale=de')->assertOk()
            ->assertJsonPath('data.title', 'Scheduling rules');

        $this->getJson(self::API.'/settings/help?locale=fr')->assertOk()
            ->assertJsonPath('data.products.title', 'Produits');

        $this->getJson(self::API.'/settings/help/nope?locale=ar')->assertNotFound();
    }

    public function test_every_translation_covers_every_section_and_field_of_the_english_help(): void
    {
        foreach (['fr', 'ar'] as $locale) {
            $translated = require lang_path("{$locale}/crm_help.php");

            $this->assertEqualsCanonicalizing(array_keys(config('crm_help')), array_keys($translated), "{$locale} sections");

            foreach (config('crm_help') as $section => $english) {
                $this->assertNotEmpty($translated[$section]['title'] ?? null, "{$locale}.{$section} title");
                $this->assertNotEmpty($translated[$section]['help'] ?? null, "{$locale}.{$section} help");
                $this->assertEqualsCanonicalizing(
                    array_keys($english['fields']),
                    array_keys($translated[$section]['fields']),
                    "{$locale}.{$section} fields",
                );

                foreach ($translated[$section]['fields'] as $name => $field) {
                    foreach (['label', 'help', 'example'] as $part) {
                        $this->assertNotEmpty($field[$part] ?? null, "{$locale}.{$section}.{$name}.{$part}");
                    }
                }
            }
        }
    }

    public function test_the_brand_is_never_transliterated_into_arabic(): void
    {
        $arabic = file_get_contents(lang_path('ar/crm_help.php'));

        $this->assertStringContainsString('Softel', $arabic);
        $this->assertStringNotContainsString('سوفتيل', $arabic);
        $this->assertStringNotContainsString('سوفتل', $arabic);

        foreach ([config_path('crm_templates.php'), config_path('crm_help.php')] as $file) {
            $this->assertStringNotContainsString('سوفتيل', file_get_contents($file), $file);
            $this->assertStringNotContainsString('سوفتل', file_get_contents($file), $file);
        }
    }
}
