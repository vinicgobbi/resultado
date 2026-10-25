<?php

namespace Database\Factories;

use App\Models\Candidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'tse_sequence' => (string) fake()->unique()->numerify('2800025#####'),
            'number' => fake()->unique()->numberBetween(10, 99),
            'ballot_name' => mb_strtoupper($name),
            'full_name' => mb_strtoupper($name),
            'party' => fake()->randomElement(['PT', 'PL', 'PSD', 'NOVO']),
            'color' => null,
        ];
    }
}
