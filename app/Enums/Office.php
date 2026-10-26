<?php

namespace App\Enums;

enum Office: string
{
    case President = 'presidente';
    case Governor = 'governador';
    case Senator = 'senador';
    case FederalDeputy = 'deputado-federal';
    case StateDeputy = 'deputado-estadual';

    /**
     * Offices disputed in a round, in display order.
     *
     * @return list<self>
     */
    public static function forRound(int $round): array
    {
        return array_values(array_filter(self::cases(), fn (self $office): bool => in_array($round, $office->rounds(), true)));
    }

    /**
     * Human readable office name (state deputies are "distritais" in the DF).
     */
    public function label(?State $state = null): string
    {
        return match ($this) {
            self::President => 'Presidente',
            self::Governor => 'Governador',
            self::Senator => 'Senador',
            self::FederalDeputy => 'Deputado Federal',
            self::StateDeputy => $state === State::DistritoFederal ? 'Deputado Distrital' : 'Deputado Estadual',
        };
    }

    /**
     * Code of the office in the TSE file names.
     */
    public function tseCode(?State $state = null): string
    {
        return match ($this) {
            self::President => '0001',
            self::Governor => '0003',
            self::Senator => '0005',
            self::FederalDeputy => '0006',
            self::StateDeputy => $state === State::DistritoFederal ? '0008' : '0007',
        };
    }

    /**
     * TSE election ("federal" or "estadual") that holds the office.
     */
    public function election(): string
    {
        return $this === self::President ? 'federal' : 'estadual';
    }

    /**
     * TSE election code of the office in a round, if it is disputed in it.
     */
    public function electionCode(int $round): ?int
    {
        if (! in_array($round, $this->rounds(), true)) {
            return null;
        }

        $code = config("eleicoes.turnos.{$round}.{$this->election()}");

        return $code === null ? null : (int) $code;
    }

    /**
     * @return list<int>
     */
    public function rounds(): array
    {
        return match ($this) {
            self::President, self::Governor => [1, 2],
            default => [1],
        };
    }

    /**
     * Whether the office is elected by the whole country.
     */
    public function isNationwide(): bool
    {
        return $this === self::President;
    }

    /**
     * Whether seats are distributed proportionally (party quotient).
     */
    public function isProportional(): bool
    {
        return in_array($this, [self::FederalDeputy, self::StateDeputy], true);
    }

    /**
     * Whether the winner needs more than half of the valid votes.
     */
    public function requiresMajority(): bool
    {
        return in_array($this, [self::President, self::Governor], true);
    }

    /**
     * Whether results are synchronized per municipality (deputy files list
     * every candidate of the state and are too large for that).
     */
    public function hasMunicipalityResults(): bool
    {
        return ! $this->isProportional();
    }

    /**
     * States with results of this office (voters abroad only vote for president).
     *
     * @return list<State>
     */
    public function states(): array
    {
        return $this->isNationwide()
            ? State::cases()
            : array_filter(State::cases(), fn (State $state): bool => $state !== State::Exterior);
    }
}
