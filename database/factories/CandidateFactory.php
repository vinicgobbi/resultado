<?php

namespace Database\Factories;

use App\Enums\Office;
use App\Enums\State;
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
            'office' => Office::President,
            'state' => null,
            'number' => fake()->unique()->numberBetween(10, 99),
            'ballot_name' => mb_strtoupper($name),
            'full_name' => mb_strtoupper($name),
            'party' => fake()->randomElement(['PT', 'PL', 'PSD', 'NOVO']),
            'color' => null,
        ];
    }

    /**
     * A candidate for a state office.
     */
    public function running(Office $office, State $state): static
    {
        return $this->state(fn (): array => ['office' => $office, 'state' => $state]);
    }
}
