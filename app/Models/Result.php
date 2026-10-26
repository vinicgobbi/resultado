<?php

namespace App\Models;

use App\Enums\Office;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $election_code
 * @property Office $office
 * @property string $scope
 * @property string $scope_code
 * @property int $sections_total
 * @property int $sections_counted
 * @property float $counted_percent
 * @property int $electorate
 * @property int $electorate_counted
 * @property int $turnout
 * @property int $abstentions
 * @property int $valid_votes
 * @property int $blank_votes
 * @property int $null_votes
 * @property array<int|string, int> $votes
 * @property int $seats
 * @property int|null $leading_candidate_id
 * @property list<int>|null $elected_candidate_ids
 * @property bool $is_final
 * @property Carbon|null $tse_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'election_code', 'office', 'scope', 'scope_code', 'sections_total', 'sections_counted', 'counted_percent',
    'electorate', 'electorate_counted', 'turnout', 'abstentions', 'valid_votes', 'blank_votes', 'null_votes',
    'votes', 'seats', 'leading_candidate_id', 'elected_candidate_ids', 'is_final', 'tse_updated_at',
])]
class Result extends Model
{
    public const string SCOPE_COUNTRY = 'br';

    public const string SCOPE_STATE = 'uf';

    public const string SCOPE_MUNICIPALITY = 'mu';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'office' => Office::class,
            'counted_percent' => 'float',
            'votes' => 'array',
            'elected_candidate_ids' => 'array',
            'is_final' => 'boolean',
            'tse_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function leadingCandidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'leading_candidate_id');
    }

    /**
     * @param  Builder<Result>  $query
     */
    public function scopeForElection(Builder $query, int $electionCode, Office $office, string $scope): void
    {
        $query->where('election_code', $electionCode)->where('office', $office)->where('scope', $scope);
    }

    /**
     * Votes per candidate id, ordered from most to least voted.
     *
     * @return array<int, int>
     */
    public function sortedVotes(): array
    {
        $votes = collect($this->votes)->mapWithKeys(fn (int $votes, int|string $candidateId): array => [(int) $candidateId => $votes]);

        return $votes->sortDesc()->all();
    }
}
