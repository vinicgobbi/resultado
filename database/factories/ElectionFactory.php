<?php

namespace Database\Factories;

use App\Enums\ElectionType;
use App\Enums\Office;
use App\Models\Election;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Election>
 */
class ElectionFactory extends Factory
{
    /**
     * Define the model's default state: the federal election of the first
     * round of 2026.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tse_code' => fake()->unique()->numberBetween(7000, 9999),
            'cycle' => 'ele2026',
            'date' => '2026-10-04',
            'round' => 1,
            'type' => ElectionType::Federal,
            'name' => 'Eleição Ordinária Federal - 2026 1º Turno',
            'second_round_code' => null,
            'places' => null,
            'offices' => [Office::President],
            'available_until' => '2034-10-04',
        ];
    }

    /**
     * The 2026 federal election (president): 6257, runoff 6258.
     */
    public function federal2026(int $round = 1): static
    {
        return $this->state(fn (): array => [
            'tse_code' => $round === 1 ? 6257 : 6258,
            'date' => $round === 1 ? '2026-10-04' : '2026-10-25',
            'round' => $round,
            'type' => ElectionType::Federal,
            'name' => "Eleição Ordinária Federal - 2026 {$round}º Turno",
            'second_round_code' => $round === 1 ? 6258 : null,
            'offices' => [Office::President],
        ]);
    }

    /**
     * The 2026 state election: 6259, runoff 6260 (governor only).
     */
    public function state2026(int $round = 1): static
    {
        return $this->state(fn (): array => [
            'tse_code' => $round === 1 ? 6259 : 6260,
            'date' => $round === 1 ? '2026-10-04' : '2026-10-25',
            'round' => $round,
            'type' => ElectionType::State,
            'name' => "Eleição Ordinária Estadual - 2026 {$round}º Turno",
            'second_round_code' => $round === 1 ? 6260 : null,
            'offices' => $round === 1
                ? [Office::Governor, Office::Senator, Office::FederalDeputy, Office::StateDeputy]
                : [Office::Governor],
        ]);
    }

    /**
     * The 2024 municipal election: 619, runoff 620 (mayor only).
     */
    public function municipal2024(int $round = 1): static
    {
        return $this->state(fn (): array => [
            'tse_code' => $round === 1 ? 619 : 620,
            'cycle' => 'ele2024',
            'date' => $round === 1 ? '2024-10-06' : '2024-10-27',
            'round' => $round,
            'type' => ElectionType::Municipal,
            'name' => "Eleição Ordinária Municipal - 2024 {$round}º Turno",
            'second_round_code' => $round === 1 ? 620 : null,
            'offices' => $round === 1 ? [Office::Mayor, Office::Councillor] : [Office::Mayor],
            'available_until' => '2028-10-06',
        ]);
    }

    /**
     * Taking place only in some places.
     *
     * @param  list<string>  $states
     * @param  array<string, string>  $municipalities  TSE code => state
     */
    public function in(array $states, array $municipalities = []): static
    {
        return $this->state(fn (): array => ['places' => ['states' => $states, 'municipalities' => $municipalities]]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }
}
