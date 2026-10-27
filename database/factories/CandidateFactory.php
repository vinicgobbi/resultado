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
            'election_code' => 6257,
            'office' => Office::President,
            'state' => null,
            'municipality' => null,
            'number' => fake()->unique()->numberBetween(10, 99),
            'ballot_name' => mb_strtoupper($name),
            'full_name' => mb_strtoupper($name),
            'party' => fake()->randomElement(['PT', 'PL', 'PSD', 'NOVO']),
            'color' => null,
        ];
    }

    /**
     * A candidate for a state office of 2026, or a municipal office of 2024
     * (in the given municipality).
     */
    public function running(Office $office, State $state, ?string $municipality = null): static
    {
        return $this->state(fn (): array => [
            'election_code' => $office->isMunicipal() ? 619 : 6259,
            'office' => $office,
            'state' => $state,
            'municipality' => $municipality,
        ]);
    }
}
