<?php

namespace Database\Factories;

use App\Models\PartyColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartyColor>
 */
class PartyColorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party' => strtoupper(fake()->unique()->lexify('???')),
            'color' => fake()->hexColor(),
        ];
    }
}
