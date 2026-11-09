<?php

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Models\FollowUpTask;
use App\Models\Prospect;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowUpTask> */
class FollowUpTaskFactory extends Factory
{
    protected $model = FollowUpTask::class;

    public function definition(): array
    {
        return [
            'prospect_id' => Prospect::factory(),
            'title' => $this->faker->sentence(4),
            'description' => null,
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'status' => FollowUpStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => FollowUpStatus::Completed, 'completed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => FollowUpStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
