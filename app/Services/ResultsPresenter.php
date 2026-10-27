<?php

namespace App\Services;

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
use App\Services\Tse\ResultSynchronizer;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shapes stored results into the props consumed by the public pages.
 *
 * @phpstan-type CandidateVotes array{candidate_id: int, votes: int, percent: float}
 * @phpstan-type FormattedResult array{counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, seats: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>}
 * @phpstan-type StateResult array{code: string, name: string, counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, seats: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>}
 * @phpstan-type Place array{code: string, name: string, counted_percent: float, is_final: bool}
 * @phpstan-type MapEntry array{0: int|null, 1: float, 2: float}
 * @phpstan-type Winner array{candidate_id: int, status: 'elected'|'mathematical'}
 * @phpstan-type Summary array{counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, seats: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>, winners: list<Winner>}
 */
class ResultsPresenter
{
    /**
     * Cached pages are dropped when a newer version is published; the
     * lifetime only bounds what is left behind.
     */
    private const int CACHE_MINUTES = 60;

    /**
     * Version the cache is written to while warming it, instead of the
     * current one.
     */
    private ?int $version = null;

    /**
     * Cache key suffixes written while warming.
     *
     * @var list<string>
     */
    private array $warmed = [];

    public function __construct(private readonly CandidateColors $colors) {}

    /**
     * Candidates that appear in a result.
     *
     * @param  array{results: list<CandidateVotes>}|null  $result
     * @return Collection<int, Candidate>
     */
    private function candidates(?array $result): Collection
    {
        $ids = array_column($result['results'] ?? [], 'candidate_id');

        return Candidate::query()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * Public data of the candidates of a result, most voted first. In
     * majority races similar colors get distinct shades; deputies keep the
     * party color, which is what tells their parties apart.
     *
     * @param  array{results: list<CandidateVotes>}|null  $result
     * @return list<array{id: int, number: int, name: string, full_name: string, party: string, color: string, photo_url: string}>
     */
    public function publicCandidates(?array $result, Office $office): array
    {
        $candidates = $this->candidates($result);
        $ordered = collect($result['results'] ?? [])
            ->map(fn (array $votes): ?Candidate => $candidates->get($votes['candidate_id']))
            ->filter()
            ->values();
        $colors = $office->isProportional() ? [] : $this->colors->assign($ordered);

        return array_values($ordered
            ->map(fn (Candidate $candidate): array => $candidate->toPublicArray($colors[$candidate->id] ?? null))
            ->all());
    }

    /**
     * Public data of the candidates of the place where the office is elected.
     *
     * @return list<array{id: int, number: int, name: string, full_name: string, party: string, color: string, photo_url: string}>
     */
    public function placeCandidates(int $electionCode, Office $office, ?State $state = null, ?Municipality $municipality = null): array
    {
        $place = $this->place($office, $state, $municipality);

        if ($place === null) {
            return [];
        }

        return $this->remember($electionCode, "{$office->value}.candidatos.{$place[1]}", fn (): array => $this->publicCandidates(
            $this->summary($electionCode, $office, $state, $municipality),
            $office,
        ));
    }

    /**
     * Scope and code of the place where the office is elected: the country,
     * the state or the municipality; null until it is chosen.
     *
     * @return array{0: string, 1: string}|null
     */
    private function place(Office $office, ?State $state, ?Municipality $municipality): ?array
    {
        return match (true) {
            $office->isNationwide() => [Result::SCOPE_COUNTRY, 'br'],
            $office->isMunicipal() => $municipality === null ? null : [Result::SCOPE_MUNICIPALITY, $municipality->tse_code],
            default => $state === null ? null : [Result::SCOPE_STATE, $state->value],
        };
    }

    /**
     * Compute the cached pages of the offices under a version that readers
     * do not use yet.
     *
     * @param  list<Office>  $offices
     * @return list<string> cache key suffixes written
     */
    public function warm(int $electionCode, array $offices, int $version): array
    {
        $this->version = $version;
        $this->warmed = [];

        try {
            foreach ($offices as $office) {
                // Thousands of municipalities: computed when visited.
                if ($office->isMunicipal()) {
                    $this->places($electionCode, $office);

                    continue;
                }

                $states = $office->isNationwide()
                    ? [null]
                    : array_map(fn (array $place): State => State::from(strtolower($place['code'])), $this->places($electionCode, $office));

                if ($office->isNationwide()) {
                    $this->states($electionCode, $office);
                }

                foreach ($states as $state) {
                    $this->placeCandidates($electionCode, $office, $state);

                    if ($office->hasMap()) {
                        $this->map($electionCode, $office, $state);
                    }
                }
            }

            return array_values(array_unique($this->warmed));
        } finally {
            $this->version = null;
        }
    }

    /**
     * Result of the place where the office is elected: the whole country for
     * president, the municipality for mayors and councillors, the state for
     * every other office.
     *
     * @return Summary|null
     */
    public function summary(int $electionCode, Office $office, ?State $state = null, ?Municipality $municipality = null): ?array
    {
        $place = $this->place($office, $state, $municipality);

        if ($place === null) {
            return null;
        }

        [$scope, $scopeCode] = $place;

        return $this->remember($electionCode, "{$office->value}.resumo.{$scopeCode}", function () use ($electionCode, $office, $scope, $scopeCode): ?array {
            $result = Result::query()->forElection($electionCode, $office, $scope)->where('scope_code', $scopeCode)->first();

            return $result === null ? null : [...$this->format($result), 'winners' => $this->winners($result, $office)];
        });
    }

    /**
     * Result of the office in every state.
     *
     * @return list<StateResult>
     */
    public function states(int $electionCode, Office $office): array
    {
        return $this->remember($electionCode, "{$office->value}.estados", fn (): array => array_values(Result::query()
            ->forElection($electionCode, $office, Result::SCOPE_STATE)
            ->get()
            ->map(function (Result $result): array {
                $state = State::from($result->scope_code);

                return [
                    'code' => $state->abbreviation(),
                    'name' => $state->label(),
                    ...$this->format($result),
                ];
            })
            ->sortBy(fn (array $state): string => $state['code'] === 'ZZ' ? 'ZZZ' : $state['name'])
            ->all()));
    }

    /**
     * States with results of the office, without the votes (deputy results
     * list every candidate of the state). For municipal offices, the
     * progress of a state is the average of its municipalities.
     *
     * @return list<Place>
     */
    public function places(int $electionCode, Office $office): array
    {
        return $this->remember($electionCode, "{$office->value}.locais", function () use ($electionCode, $office): array {
            $rows = $office->isMunicipal()
                ? Result::query()
                    ->forElection($electionCode, $office, Result::SCOPE_MUNICIPALITY)
                    ->join('municipalities', 'municipalities.tse_code', '=', 'results.scope_code')
                    ->groupBy('municipalities.state')
                    ->selectRaw('municipalities.state as scope_code, avg(results.counted_percent) as counted_percent, min(results.is_final) as is_final')
                    ->toBase()
                    ->get()
                : Result::query()
                    ->forElection($electionCode, $office, Result::SCOPE_STATE)
                    ->toBase()
                    ->get(['scope_code', 'counted_percent', 'is_final']);

            return array_values($rows
                ->map(function (object $row): array {
                    $state = State::from((string) $row->scope_code);

                    return [
                        'code' => $state->abbreviation(),
                        'name' => $state->label(),
                        'counted_percent' => round((float) $row->counted_percent, 2),
                        'is_final' => (bool) $row->is_final,
                    ];
                })
                ->sortBy('name')
                ->all());
        });
    }

    /**
     * Municipalities of a state with results of a municipal office.
     *
     * @return list<array{code: string, name: string, counted_percent: float, is_final: bool}>
     */
    public function municipalities(int $electionCode, Office $office, State $state): array
    {
        return $this->remember($electionCode, "{$office->value}.municipios.{$state->value}", fn (): array => array_values(Result::query()
            ->forElection($electionCode, $office, Result::SCOPE_MUNICIPALITY)
            ->join('municipalities', 'municipalities.tse_code', '=', 'results.scope_code')
            ->where('municipalities.state', $state->value)
            ->orderBy('municipalities.name')
            ->toBase()
            ->get(['municipalities.tse_code', 'municipalities.name', 'results.counted_percent', 'results.is_final'])
            ->map(fn (object $row): array => [
                'code' => (string) $row->tse_code,
                'name' => (string) $row->name,
                'counted_percent' => round((float) $row->counted_percent, 2),
                'is_final' => (bool) $row->is_final,
            ])
            ->all()));
    }

    /**
     * Compact map data keyed by IBGE code: [leader id, leader %, counted %],
     * for the whole country or for one state.
     *
     * @return array<string, MapEntry>
     */
    public function map(int $electionCode, Office $office, ?State $state = null): array
    {
        return $this->remember($electionCode, "{$office->value}.mapa.".($state->value ?? 'br'), fn (): array => Result::query()
            ->forElection($electionCode, $office, Result::SCOPE_MUNICIPALITY)
            ->join('municipalities', 'municipalities.tse_code', '=', 'results.scope_code')
            ->whereNotNull('municipalities.ibge_code')
            ->when($state !== null, fn ($query) => $query->where('municipalities.state', $state?->value))
            ->get(['municipalities.ibge_code', 'results.votes', 'results.valid_votes', 'results.leading_candidate_id', 'results.counted_percent'])
            ->mapWithKeys(function (Result $result): array {
                $votes = $result->votes;
                $leaderVotes = $result->leading_candidate_id === null ? 0 : ($votes[$result->leading_candidate_id] ?? 0);

                return [(string) $result->getAttribute('ibge_code') => [
                    $result->leading_candidate_id,
                    $this->percent($leaderVotes, max($result->valid_votes, array_sum($votes))),
                    round($result->counted_percent, 2),
                ]];
            })
            ->all());
    }

    /**
     * Full result of one municipality.
     *
     * @return FormattedResult|null
     */
    public function municipality(int $electionCode, Office $office, Municipality $municipality): ?array
    {
        $result = Result::query()
            ->forElection($electionCode, $office, Result::SCOPE_MUNICIPALITY)
            ->where('scope_code', $municipality->tse_code)
            ->first();

        return $result === null ? null : $this->format($result);
    }

    /**
     * Value cached until the next synchronization of the election.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(int $electionCode, string $key, Closure $callback): mixed
    {
        if ($this->version !== null) {
            $this->warmed[] = $key;
        }

        return Cache::remember(ResultSynchronizer::cacheKey($electionCode, $key, $this->version), now()->addMinutes(self::CACHE_MINUTES), $callback);
    }

    /**
     * Who won, if anyone: officially elected by the TSE, or mathematically
     * elected because even if every voter still to be counted voted validly
     * against them, nobody could catch up.
     *
     * With a majority (president, governor, mayor of a large municipality)
     * the leader must keep more than half of the valid votes (in the runoff:
     * a lead larger than the voters left). In a plurality (senator, mayor of
     * a small municipality, one vote per seat) each of the first candidates
     * must lead the best one outside the seats by more than the voters left. Proportional seats depend on the party quotient, so only
     * the TSE declares them.
     *
     * @return list<Winner>
     */
    private function winners(Result $result, Office $office): array
    {
        if (! empty($result->elected_candidate_ids)) {
            return array_map(fn (int $candidateId): array => ['candidate_id' => $candidateId, 'status' => 'elected'], $result->elected_candidate_ids);
        }

        if ($office->isProportional() || $result->leading_candidate_id === null || $result->sections_counted === 0) {
            return [];
        }

        $votes = $result->sortedVotes();
        // Voters in counted sections; turnout + abstentions is the same up to
        // the few sections that were never installed (a safe overestimate of
        // the voters left), and does not depend on a newer column.
        $counted = (int) $result->electorate_counted ?: $result->turnout + $result->abstentions;
        $votersLeft = max($result->electorate - $counted, 0);
        $status = $result->is_final ? 'elected' : 'mathematical';

        if ($office->requiresMajority($result->electorate)) {
            $leaderVotes = reset($votes) ?: 0;
            $validVotes = max($result->valid_votes, array_sum($votes));

            return 2 * $leaderVotes > $validVotes + $votersLeft
                ? [['candidate_id' => (int) array_key_first($votes), 'status' => $status]]
                : [];
        }

        $challengerVotes = array_values($votes)[$result->seats] ?? 0;
        $winners = [];

        foreach (array_slice($votes, 0, $result->seats, preserve_keys: true) as $candidateId => $count) {
            if ($count - $challengerVotes > $votersLeft) {
                $winners[] = ['candidate_id' => $candidateId, 'status' => $status];
            }
        }

        return $winners;
    }

    /**
     * @return FormattedResult
     */
    private function format(Result $result): array
    {
        $votes = $result->sortedVotes();
        // Deputy valid votes also count votes for the party only.
        $validVotes = max($result->valid_votes, array_sum($votes));

        return [
            'counted_percent' => round($result->counted_percent, 2),
            'sections_total' => $result->sections_total,
            'sections_counted' => $result->sections_counted,
            'electorate' => $result->electorate,
            'turnout' => $result->turnout,
            'abstentions' => $result->abstentions,
            'valid_votes' => $result->valid_votes,
            'blank_votes' => $result->blank_votes,
            'null_votes' => $result->null_votes,
            'seats' => $result->seats,
            'is_final' => $result->is_final,
            'leader_id' => $result->leading_candidate_id,
            'updated_at' => $result->tse_updated_at?->toIso8601String(),
            'results' => array_map(fn (int $count, int $candidateId): array => [
                'candidate_id' => $candidateId,
                'votes' => $count,
                'percent' => $this->percent($count, $validVotes),
            ], $votes, array_keys($votes)),
        ];
    }

    private function percent(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 2);
    }
}
