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
}
