<?php

namespace Database\Factories;

use App\Models\Prospect;
use App\Models\SalesStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bypasses StageTransitionService on purpose (test fixtures only); use the
 * API or ProspectService for real data so stage history is written.
 *
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'contact_person' => $this->faker->name(),
            'phone' => $this->faker->numerify('0#########'),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'notes' => null,
            'sales_stage_id' => SalesStage::factory(),
        ];
    }

    public function inStage(SalesStage $stage): static
    {
        return $this->state(['sales_stage_id' => $stage->id]);
    }
}
