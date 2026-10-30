<?php

namespace Database\Factories;

use App\Enums\NoticeLevel;
use App\Models\SiteNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteNotice>
 */
class SiteNoticeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'level' => NoticeLevel::Info,
        ];
    }

    public function warning(): static
    {
        return $this->state(fn (): array => ['level' => NoticeLevel::Warning]);
    }
}
