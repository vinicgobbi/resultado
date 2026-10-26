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
     * @return list<int>
     */
    public static function rounds(): array
    {
        return array_map(intval(...), array_keys(config('eleicoes.turnos')));
    }

    /**
     * Candidates that appear in a result.
     *
     * @param  array{results: list<CandidateVotes>}|null  $result
     * @return Collection<int, Candidate>
     */
    public function candidates(?array $result): Collection
    {
        $ids = array_column($result['results'] ?? [], 'candidate_id');

        return Candidate::query()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * Result of the place where the office is elected: the whole country for
     * president, the state for every other office.
     *
     * @return Summary|null
     */
    public function summary(int $electionCode, Office $office, ?State $state = null): ?array
    {
        $scope = $office->isNationwide() ? Result::SCOPE_COUNTRY : Result::SCOPE_STATE;
        $scopeCode = $office->isNationwide() ? 'br' : $state?->value;

        if ($scopeCode === null) {
            return null;
        }

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
     * list every candidate of the state).
     *
     * @return list<Place>
     */
    public function places(int $electionCode, Office $office): array
    {
        return $this->remember($electionCode, "{$office->value}.locais", fn (): array => array_values(Result::query()
            ->forElection($electionCode, $office, Result::SCOPE_STATE)
            ->get(['scope_code', 'counted_percent', 'is_final'])
            ->map(function (Result $result): array {
                $state = State::from($result->scope_code);

                return [
                    'code' => $state->abbreviation(),
                    'name' => $state->label(),
                    'counted_percent' => round($result->counted_percent, 2),
                    'is_final' => $result->is_final,
                ];
            })
            ->sortBy('name')
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
        return Cache::remember(ResultSynchronizer::cacheKey($electionCode, $key), now()->addMinutes(5), $callback);
    }

    /**
     * Who won, if anyone: officially elected by the TSE, or mathematically
     * elected because even if every voter still to be counted voted validly
     * against them, nobody could catch up.
     *
     * With a majority (president, governor) the leader must keep more than
     * half of the valid votes (in the runoff: a lead larger than the voters
     * left). In a plurality (senator, one vote per seat) each of the first
     * candidates must lead the best one outside the seats by more than the
     * voters left. Proportional seats depend on the party quotient, so only
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

        if ($office->requiresMajority()) {
            $leaderVotes = reset($votes) ?: 0;
            $validVotes = max($result->valid_votes, array_sum($votes));

            return 2 * $leaderVotes > $validVotes + $votersLeft
                ? [['candidate_id' => (int) array_key_first($votes), 'status' => $status]]
                : [];
        }

        $challengerVotes = array_values($votes)[$result->seats] ?? 0;

        return collect($votes)
            ->take($result->seats)
            ->filter(fn (int $count): bool => $count - $challengerVotes > $votersLeft)
            ->map(fn (int $count, int $candidateId): array => ['candidate_id' => $candidateId, 'status' => $status])
            ->values()
            ->all();
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
