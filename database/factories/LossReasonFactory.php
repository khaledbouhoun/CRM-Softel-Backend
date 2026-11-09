<?php

namespace Database\Factories;

use App\Models\LossReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LossReason> */
class LossReasonFactory extends Factory
{
    protected $model = LossReason::class;

    public function definition(): array
    {
        return [
            'name' => 'Reason '.$this->faker->unique()->numerify('####'),
            'description' => null,
            'requires_note' => false,
            'sort_order' => $this->faker->numberBetween(1, 50),
            'is_active' => true,
        ];
    }

    public function requiresNote(): static
    {
        return $this->state(['requires_note' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
