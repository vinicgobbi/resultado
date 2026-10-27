<?php

namespace Database\Factories;

use App\Enums\MaintenanceTask;
use App\Models\MaintenanceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRun>
 */
class MaintenanceRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task' => fake()->randomElement(MaintenanceTask::cases()),
            'status' => MaintenanceRun::QUEUED,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => ['status' => MaintenanceRun::RUNNING, 'started_at' => now()]);
    }
}
