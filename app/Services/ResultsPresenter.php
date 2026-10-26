<?php

namespace App\Services;

use App\Enums\State;
use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shapes stored results into the props consumed by the public pages.
 *
 * @phpstan-type CandidateVotes array{candidate_id: int, votes: int, percent: float}
 * @phpstan-type FormattedResult array{counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>}
 * @phpstan-type StateResult array{code: string, name: string, counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>}
 * @phpstan-type MapEntry array{0: int|null, 1: float, 2: float}
 * @phpstan-type Winner array{candidate_id: int, status: 'elected'|'mathematical'}
 * @phpstan-type CountrySummary array{counted_percent: float, sections_total: int, sections_counted: int, electorate: int, turnout: int, abstentions: int, valid_votes: int, blank_votes: int, null_votes: int, is_final: bool, leader_id: int|null, updated_at: string|null, results: list<CandidateVotes>, winner: Winner|null}
 */
class ResultsPresenter
{
    /**
     * Election code configured for a round, falling back to the active round.
     */
    public static function electionCode(int $round): int
    {
        return (int) config("eleicoes.turnos.{$round}");
    }

    /**
     * @return list<int>
     */
    public static function rounds(): array
    {
        return array_map(intval(...), array_keys(config('eleicoes.turnos')));
    }

    /**
     * Candidates that appear in the results of an election.
     *
     * @return Collection<int, Candidate>
     */
    public function candidates(int $electionCode): Collection
    {
        $ids = collect($this->data($electionCode)['country']['results'] ?? [])->pluck('candidate_id');

        return Candidate::query()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * @return CountrySummary|null
     */
    public function summary(int $electionCode): ?array
    {
        return $this->data($electionCode)['country'];
    }

    /**
     * @return list<StateResult>
     */
    public function states(int $electionCode): array
    {
        return $this->data($electionCode)['states'];
    }

    /**
     * Compact map data keyed by IBGE code: [leader id, leader %, counted %].
     *
     * @return array<string, MapEntry>
     */
    public function map(int $electionCode): array
    {
        return $this->data($electionCode)['map'];
    }

    /**
     * Full result of one municipality.
     *
     * @return FormattedResult|null
     */
    public function municipality(int $electionCode, Municipality $municipality): ?array
    {
        $result = Result::query()
            ->forElection($electionCode, Result::SCOPE_MUNICIPALITY)
            ->where('scope_code', $municipality->tse_code)
            ->first();

        return $result === null ? null : $this->format($result);
    }

    /**
     * Results cached until the next synchronization.
     *
     * @return array{country: CountrySummary|null, states: list<StateResult>, map: array<string, MapEntry>}
     */
    private function data(int $electionCode): array
    {
        return Cache::remember(ResultSynchronizer::cacheKey($electionCode), now()->addMinutes(5), function () use ($electionCode): array {
            $country = Result::query()->forElection($electionCode, Result::SCOPE_COUNTRY)->first();

            $states = array_values(Result::query()
                ->forElection($electionCode, Result::SCOPE_STATE)
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
                ->all());

            $map = Result::query()
                ->forElection($electionCode, Result::SCOPE_MUNICIPALITY)
                ->join('municipalities', 'municipalities.tse_code', '=', 'results.scope_code')
                ->whereNotNull('municipalities.ibge_code')
                ->get(['municipalities.ibge_code', 'results.votes', 'results.valid_votes', 'results.leading_candidate_id', 'results.counted_percent'])
                ->mapWithKeys(function (Result $result): array {
                    $votes = $result->votes;
                    $leaderVotes = $result->leading_candidate_id === null ? 0 : ($votes[$result->leading_candidate_id] ?? 0);

                    return [(string) $result->getAttribute('ibge_code') => [
                        $result->leading_candidate_id,
                        $this->percent($leaderVotes, array_sum($votes)),
                        round($result->counted_percent, 2),
                    ]];
                })
                ->all();

            return [
                'country' => $country === null ? null : [...$this->format($country), 'winner' => $this->winner($country)],
                'states' => $states,
                'map' => $map,
            ];
        });
    }

    /**
     * Who won, if anyone: officially elected by the TSE, or mathematically
     * elected because even if every voter still to be counted voted validly
     * against the leader, the leader would keep more than half of the valid
     * votes (in the runoff: a lead larger than the voters left).
     *
     * @return Winner|null
     */
    private function winner(Result $country): ?array
    {
        if ($country->elected_candidate_id !== null) {
            return ['candidate_id' => $country->elected_candidate_id, 'status' => 'elected'];
        }

        $leaderId = $country->leading_candidate_id;

        if ($leaderId === null || $country->sections_counted === 0) {
            return null;
        }

        $votes = $country->votes;
        $leaderVotes = $votes[$leaderId] ?? 0;
        $validVotes = max($country->valid_votes, array_sum($votes));
        // Voters in counted sections; turnout + abstentions is the same up to
        // the few sections that were never installed (a safe overestimate of
        // the voters left), and does not depend on a newer column.
        $counted = (int) $country->electorate_counted ?: $country->turnout + $country->abstentions;
        $votersLeft = max($country->electorate - $counted, 0);

        if (2 * $leaderVotes <= $validVotes + $votersLeft) {
            return null;
        }

        return ['candidate_id' => $leaderId, 'status' => $country->is_final ? 'elected' : 'mathematical'];
    }

    /**
     * @return FormattedResult
     */
    private function format(Result $result): array
    {
        $votes = $result->sortedVotes();
        $nominal = array_sum($votes);

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
            'is_final' => $result->is_final,
            'leader_id' => $result->leading_candidate_id,
            'updated_at' => $result->tse_updated_at?->toIso8601String(),
            'results' => array_map(fn (int $count, int $candidateId): array => [
                'candidate_id' => $candidateId,
                'votes' => $count,
                'percent' => $this->percent($count, $nominal),
            ], $votes, array_keys($votes)),
        ];
    }

    private function percent(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 2);
    }
}
