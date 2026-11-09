<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Prospect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Activity> */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'prospect_id' => Prospect::factory(),
            'type' => $this->faker->randomElement(ActivityType::cases()),
            'description' => $this->faker->sentence(),
            'outcome' => null,
            'activity_at' => now()->subDays($this->faker->numberBetween(0, 30)),
        ];
    }
}
