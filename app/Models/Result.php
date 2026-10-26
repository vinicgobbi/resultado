<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $election_code
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
 * @property int|null $leading_candidate_id
 * @property int|null $elected_candidate_id
 * @property bool $is_final
 * @property Carbon|null $tse_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'election_code', 'scope', 'scope_code', 'sections_total', 'sections_counted', 'counted_percent',
    'electorate', 'electorate_counted', 'turnout', 'abstentions', 'valid_votes', 'blank_votes', 'null_votes',
    'votes', 'leading_candidate_id', 'elected_candidate_id', 'is_final', 'tse_updated_at',
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
            'counted_percent' => 'float',
            'votes' => 'array',
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
    public function scopeForElection(Builder $query, int $electionCode, string $scope): void
    {
        $query->where('election_code', $electionCode)->where('scope', $scope);
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
