<?php

namespace Tests\Feature;

use App\Enums\AppointmentKind;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Prospect;
use Tests\Concerns\CreatesPortalFixtures;
use Tests\TestCase;

class FixedAppointmentTypesTest extends TestCase
{
    use CreatesPortalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortal();
    }

    public function test_exactly_the_four_fixed_types_exist(): void
    {
        $this->assertEqualsCanonicalizing(AppointmentKind::values(), AppointmentType::query()->pluck('code')->all());
        $this->assertEqualsCanonicalizing(AppointmentKind::values(), $this->getJson(self::API.'/lookups')->json('data.appointment_kinds'));
    }

    public function test_new_types_cannot_be_created_or_removed(): void
    {
        $this->postJson(self::API.'/config/appointment-types', ['code' => 'webinar', 'name' => 'Webinar', 'group' => 'meeting'])
            ->assertStatus(422)->assertJsonPath('error_code', 'APPOINTMENT_TYPES_FIXED');

        $demo = AppointmentType::query()->where('code', 'demo')->firstOrFail();
        $this->deleteJson(self::API."/config/appointment-types/{$demo->id}")->assertStatus(422);
        $this->assertTrue($demo->fresh()->is_active);
    }

    public function test_identity_is_immutable_but_presentation_can_change(): void
    {
        $training = AppointmentType::query()->where('code', 'training')->firstOrFail();
        $url = self::API."/config/appointment-types/{$training->id}";

        $this->putJson($url, ['name' => 'Workshop'])->assertStatus(422);
        $this->putJson($url, ['code' => 'workshop'])->assertStatus(422);
        $this->putJson($url, ['group' => 'meeting'])->assertStatus(422);
        $this->putJson($url, ['is_active' => false])->assertStatus(422);

        // Echoing the object unchanged is fine; so is changing presentation fields.
        $this->putJson($url, ['name' => 'Training', 'is_active' => true, 'default_duration_minutes' => 90, 'color' => '#112233'])->assertOk();
        $this->assertSame(90, $training->fresh()->default_duration_minutes);
    }

    public function test_migration_folds_custom_types_into_the_fixed_ones(): void
    {
        $custom = AppointmentType::query()->create(['code' => 'webinar', 'name' => 'Webinar', 'group' => 'training']);
        $prospect = Prospect::factory()->inStage($this->demoStage)->create();
        $appointment = Appointment::query()->create([
            'appointment_type_id' => $custom->id, 'prospect_id' => $prospect->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'confirmed',
        ]);

        (require database_path('migrations/2026_11_18_000003_fix_appointment_types.php'))->up();

        $this->assertFalse(AppointmentType::query()->where('code', 'webinar')->exists());
        $this->assertSame('training', $appointment->fresh()->type->code);
        $this->assertSame(4, AppointmentType::query()->count());
    }
}
