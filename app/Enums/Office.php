<?php

namespace App\Enums;

use App\Models\Result;

enum Office: string
{
    case President = 'presidente';
    case Governor = 'governador';
    case Senator = 'senador';
    case FederalDeputy = 'deputado-federal';
    case StateDeputy = 'deputado-estadual';
    case Mayor = 'prefeito';
    case Councillor = 'vereador';
    case DistrictCouncillor = 'conselheiro-distrital';

    /**
     * Mayors of municipalities with more voters than this need more than
     * half of the valid votes (otherwise the most voted wins).
     */
    public const int MAYOR_MAJORITY_ELECTORATE = 200_000;

    /**
     * Office of a "cp" code of the TSE elections index. State and district
     * deputies are the same office.
     */
    public static function fromTseCode(int $code): ?self
    {
        return match ($code) {
            1 => self::President,
            3 => self::Governor,
            5 => self::Senator,
            6 => self::FederalDeputy,
            7, 8 => self::StateDeputy,
            11 => self::Mayor,
            13 => self::Councillor,
            25 => self::DistrictCouncillor,
            default => null,
        };
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
            self::Mayor => 'Prefeito',
            self::Councillor => 'Vereador',
            self::DistrictCouncillor => 'Conselheiro Distrital',
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
            self::Mayor => '0011',
            self::Councillor => '0013',
            self::DistrictCouncillor => '0025',
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
     * Result scope where the office is elected: the country, each state or
     * each municipality.
     */
    public function electedScope(): string
    {
        return match (true) {
            $this->isNationwide() => Result::SCOPE_COUNTRY,
            $this->isMunicipal() => Result::SCOPE_MUNICIPALITY,
            default => Result::SCOPE_STATE,
        };
    }

    /**
     * Whether the office is elected in each municipality.
     */
    public function isMunicipal(): bool
    {
        return in_array($this, [self::Mayor, self::Councillor, self::DistrictCouncillor], true);
    }

    /**
     * Whether seats are distributed proportionally (party quotient).
     */
    public function isProportional(): bool
    {
        return in_array($this, [self::FederalDeputy, self::StateDeputy, self::Councillor], true);
    }

    /**
     * Whether the winner needs more than half of the valid votes, given the
     * electorate of the place.
     */
    public function requiresMajority(int $electorate): bool
    {
        return match ($this) {
            self::President, self::Governor => true,
            self::Mayor => $electorate > self::MAYOR_MAJORITY_ELECTORATE,
            default => false,
        };
    }

    /**
     * Whether the result is shown on the map of municipalities: offices
     * elected in a larger place and not proportional (deputy files list
     * every candidate of the state and are too large for that).
     */
    public function hasMap(): bool
    {
        return ! $this->isProportional() && ! $this->isMunicipal();
    }

    /**
     * States where the office can be disputed: voters abroad only vote for
     * president, and the DF has no municipalities.
     *
     * @return list<State>
     */
    public function states(): array
    {
        return array_values(array_filter(State::cases(), fn (State $state): bool => match (true) {
            $this->isNationwide() => true,
            $this->isMunicipal() => ! in_array($state, [State::Exterior, State::DistritoFederal], true),
            default => $state !== State::Exterior,
        }));
    }
}
