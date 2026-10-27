<?php

namespace App\Services;

use App\Enums\Office;
use App\Enums\State;
use App\Models\Election;
use App\Models\Municipality;
use App\Models\Result;

/**
 * An office where it is elected: the country, a state or a municipality
 * (by its TSE code). What a browser follows.
 */
final readonly class ResultPlace
{
    public function __construct(
        public Office $office,
        public string $scopeCode,
    ) {}

    /**
     * Whether the place exists for the office.
     */
    public function exists(): bool
    {
        return match ($this->office->electedScope()) {
            Result::SCOPE_COUNTRY => $this->scopeCode === 'br',
            Result::SCOPE_STATE => State::tryFrom($this->scopeCode) !== null && $this->scopeCode !== State::Exterior->value,
            default => Municipality::query()->where('tse_code', $this->scopeCode)->exists(),
        };
    }

    public function state(): ?State
    {
        return match ($this->office->electedScope()) {
            Result::SCOPE_STATE => State::tryFrom($this->scopeCode),
            Result::SCOPE_MUNICIPALITY => State::tryFrom((string) Municipality::query()->where('tse_code', $this->scopeCode)->value('state')),
            default => null,
        };
    }

    /**
     * "Brasil", "São Paulo" or "CAMPINAS (SP)".
     */
    public function name(): string
    {
        $state = $this->state();

        if ($this->office->electedScope() !== Result::SCOPE_MUNICIPALITY) {
            return $state?->label() ?? 'Brasil';
        }

        $name = (string) Municipality::query()->where('tse_code', $this->scopeCode)->value('name');

        return $state === null ? $name : "{$name} ({$state->abbreviation()})";
    }

    /**
     * "Governador · São Paulo".
     */
    public function label(): string
    {
        return "{$this->office->label($this->state())} · {$this->name()}";
    }

    /**
     * The most recent election, already held, where the office was disputed
     * in this place (a runoff only takes place in some of them).
     */
    public function latestElection(): ?Election
    {
        return Election::query()
            ->whereJsonContains('offices', $this->office->value)
            ->whereDate('date', '<=', today())
            ->orderByDesc('date')
            ->get()
            ->first(fn (Election $election): bool => match ($this->office->electedScope()) {
                Result::SCOPE_STATE => in_array($this->state(), $election->statesFor($this->office), true),
                Result::SCOPE_MUNICIPALITY => $election->municipalities() === null || array_key_exists($this->scopeCode, $election->municipalities()),
                default => true,
            });
    }

    /**
     * Results page of the place in an election.
     */
    public function url(Election $election): string
    {
        return route('elections.show', [
            $election,
            'cargo' => $this->office->value,
            ...match ($this->office->electedScope()) {
                Result::SCOPE_STATE => ['uf' => $this->scopeCode],
                Result::SCOPE_MUNICIPALITY => ['uf' => $this->state()?->value, 'municipio' => $this->scopeCode],
                default => [],
            },
        ], absolute: false);
    }
}
