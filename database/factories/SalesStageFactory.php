<?php

namespace Database\Factories;

use App\Enums\StageType;
use App\Models\SalesStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesStage> */
class SalesStageFactory extends Factory
{
    protected $model = SalesStage::class;

    public function definition(): array
    {
        return [
            'name' => 'Stage '.$this->faker->unique()->numerify('####'),
            'description' => null,
            'color' => '#3366FF',
            'sort_order' => $this->faker->numberBetween(1, 50),
            'stage_type' => StageType::Open,
            'is_active' => true,
        ];
    }

    public function open(): static
    {
        return $this->state(['stage_type' => StageType::Open]);
    }

    public function won(): static
    {
        return $this->state(['stage_type' => StageType::Won]);
    }

    public function lost(): static
    {
        return $this->state(['stage_type' => StageType::Lost]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
