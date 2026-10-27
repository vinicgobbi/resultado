<?php

namespace App\Services;

use App\Enums\Office;
use App\Models\Result;

/**
 * Who won a race, or who goes to its runoff, as soon as it can no longer
 * change: what the results page shows and what notifies the followers.
 *
 * @phpstan-type Winner array{candidate_id: int, status: 'elected'|'mathematical'}
 * @phpstan-type Runoff array{status: 'confirmed'|'mathematical', candidate_ids: list<int>}
 */
class RaceOutcome
{
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
    public function winners(Result $result, Office $office): array
    {
        if (! empty($result->elected_candidate_ids)) {
            return array_map(fn (int $candidateId): array => ['candidate_id' => $candidateId, 'status' => 'elected'], $result->elected_candidate_ids);
        }

        if ($office->isProportional() || $result->leading_candidate_id === null || $result->sections_counted === 0) {
            return [];
        }

        $votes = $result->sortedVotes();
        $votersLeft = $this->votersLeft($result);
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
     * Who goes to the runoff, once there is sure to be one: in the first
     * round of an office elected by majority, when not even the leader could
     * reach more than half of the valid votes with every voter left.
     *
     * A candidate is sure of a place when the two closest rivals could not
     * both pass them with the voters left; a tie is not sure. Once the count
     * is final, the first two are confirmed.
     *
     * @return Runoff|null
     */
    public function runoff(Result $result, Office $office): ?array
    {
        if (! $office->requiresMajority($result->electorate) || ! empty($result->elected_candidate_ids) || $result->sections_counted === 0) {
            return null;
        }

        $votes = $result->sortedVotes();
        $votersLeft = $this->votersLeft($result);
        $validVotes = max($result->valid_votes, array_sum($votes));
        $leaderVotes = reset($votes) ?: 0;

        if (2 * ($leaderVotes + $votersLeft) > $validVotes + $votersLeft) {
            return null;
        }

        $qualified = [];

        foreach (array_slice($votes, 0, 2, preserve_keys: true) as $candidateId => $count) {
            $rivals = array_slice(array_values(array_diff_key($votes, [$candidateId => true])), 0, 2);
            $votesToPass = array_sum(array_map(fn (int $rival): int => max($count - $rival, 0), $rivals));

            if (count($rivals) < 2 || $votesToPass > $votersLeft) {
                $qualified[] = (int) $candidateId;
            }
        }

        return ['status' => $result->is_final ? 'confirmed' : 'mathematical', 'candidate_ids' => $qualified];
    }

    /**
     * Voters still to be counted, as if every one of them voted validly.
     * Voters in counted sections are turnout + abstentions up to the few
     * sections that were never installed (a safe overestimate of the voters
     * left), when the TSE did not send them.
     */
    private function votersLeft(Result $result): int
    {
        $counted = (int) $result->electorate_counted ?: $result->turnout + $result->abstentions;

        return max($result->electorate - $counted, 0);
    }

    /**
     * Whatever is already decided in the race, as a key that changes when
     * something more is decided: the winners, or the runoff and who is sure
     * of a place in it. Empty while nothing is decided.
     */
    public function decidedKey(Result $result, Office $office, bool $isFirstRound): string
    {
        $winners = array_column($this->winners($result, $office), 'candidate_id');
        $runoff = $isFirstRound ? $this->runoff($result, $office) : null;

        return match (true) {
            $winners !== [] => 'eleito:'.implode(',', $winners),
            $runoff !== null => 'segundo-turno:'.implode(',', $runoff['candidate_ids']),
            default => '',
        };
    }
}
