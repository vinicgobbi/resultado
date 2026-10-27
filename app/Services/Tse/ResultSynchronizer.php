<?php

namespace App\Services\Tse;

use App\Enums\Office;
use App\Enums\State;
use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
use App\Services\ResultsPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Downloads the results of every office of an election from the TSE and
 * stores them locally.
 *
 * Municipality files are only downloaded when the per-state progress file
 * ("-ab.json") reports a newer totalization time than the stored one, so a
 * sync during the count only fetches what actually changed. Once every result
 * of an election is final and was downloaded without failures, the election
 * is complete and the TSE is no longer requested for it.
 *
 * Only results with a newer totalization time are written. When anything
 * changed, the cached pages of the election are computed under a new version
 * before readers move to it, so visitors never compute them.
 */
class ResultSynchronizer
{
    /**
     * Candidate ids keyed by TSE sequence number.
     *
     * @var array<string, int>
     */
    private array $candidateIds = [];

    /**
     * Whether every result downloaded in the current sync is final.
     */
    private bool $allFinal = true;

    /**
     * Whether the current sync stored any result.
     */
    private bool $changed = false;

    /**
     * Whether the current sync rewrites results that did not change.
     */
    private bool $full = false;

    public function __construct(
        private readonly TseClient $client,
        private readonly ResultsPresenter $presenter,
    ) {}

    /**
     * @param  list<Office>  $offices  offices of the election to synchronize
     * @return array{states: int, municipalities: int, counted_percent: float|null, country_changed: bool, complete: bool}
     *
     * @throws RateLimitedException
     */
    public function sync(int $electionCode, array $offices, bool $full = false): array
    {
        $failuresBefore = $this->client->failedRequests();
        $this->allFinal = true;
        $this->changed = false;
        $this->full = $full;

        // Every election lists the same municipalities: a full sync refreshes
        // them once, with the federal election.
        if (($full && in_array(Office::President, $offices, true)) || Municipality::query()->doesntExist()) {
            $this->importMunicipalities($electionCode);
        }

        $country = null;
        $countryChanged = false;

        if (in_array(Office::President, $offices, true)) {
            $country = ResultPayload::fromTse($this->client->fetch($this->client->countryResultUrl($electionCode, Office::President)));

            $previousUpdate = Result::query()
                ->forElection($electionCode, Office::President, Result::SCOPE_COUNTRY)
                ->value('tse_updated_at');

            $countryChanged = $previousUpdate === null
                || Carbon::parse($previousUpdate)->format('Y-m-d H:i:s') !== $country->updatedAt?->format('Y-m-d H:i:s');

            $this->allFinal = $country->isFinal;
            $this->storeCandidates(Office::President, null, $country);
            $this->storeResults($electionCode, Office::President, Result::SCOPE_COUNTRY, ['br' => $country]);
        }

        $states = 0;
        $disputedStates = [];

        foreach ($offices as $office) {
            $disputedStates[$office->value] = $this->disputedStates($electionCode, $office);
            $states += $this->syncStates($electionCode, $office, $disputedStates[$office->value]);
        }

        $municipalities = $this->syncMunicipalities(
            $electionCode,
            array_values(array_filter($offices, fn (Office $office): bool => $office->hasMunicipalityResults())),
            $disputedStates,
            $full,
        );

        if ($this->changed) {
            $this->publishVersion($electionCode, $offices);
        }

        $complete = $states > 0 && $this->allFinal && $this->client->failedRequests() === $failuresBefore;
        Cache::forever(self::completeKey($electionCode), $complete);

        return [
            'states' => $states,
            'municipalities' => $municipalities,
            'counted_percent' => $country?->countedPercent,
            'country_changed' => $countryChanged,
            'complete' => $complete,
        ];
    }

    /**
     * Whether the last sync of the election downloaded every result, all of
     * them final, so there is nothing left to download.
     */
    public static function isComplete(int $electionCode): bool
    {
        return (bool) Cache::get(self::completeKey($electionCode), false);
    }

    private static function completeKey(int $electionCode): string
    {
        return "eleicoes.resultados.{$electionCode}.concluida";
    }

    /**
     * Cache key used by readers of the results of an election; every sync
     * that changes something moves the keys of the election to a new version.
     */
    public static function cacheKey(int $electionCode, string $suffix, ?int $version = null): string
    {
        $version ??= self::version($electionCode);

        return "eleicoes.resultados.{$electionCode}.v{$version}.{$suffix}";
    }

    /**
     * Current version of the cached results of an election, read once per
     * request.
     */
    public static function version(int $electionCode): int
    {
        return (int) Cache::memo()->get(self::versionKey($electionCode), 0);
    }

    /**
     * Move readers of the election to a new, empty version (the cached pages
     * are computed again on their next visit).
     */
    public static function invalidate(int $electionCode): void
    {
        Cache::memo()->forever(self::versionKey($electionCode), self::version($electionCode) + 1);
    }

    /**
     * Compute the cached pages under the next version, move readers to it and
     * drop the pages of the previous one.
     *
     * @param  list<Office>  $offices
     */
    private function publishVersion(int $electionCode, array $offices): void
    {
        $previous = self::version($electionCode);
        $suffixes = $this->presenter->warm($electionCode, $offices, $previous + 1);

        Cache::memo()->forever(self::versionKey($electionCode), $previous + 1);

        foreach ($suffixes as $suffix) {
            Cache::forget(self::cacheKey($electionCode, $suffix, $previous));
        }
    }

    private static function versionKey(int $electionCode): string
    {
        return "eleicoes.resultados.{$electionCode}.versao";
    }

    public function importMunicipalities(int $electionCode): int
    {
        $config = $this->client->fetch($this->client->municipalitiesConfigUrl($electionCode));
        $now = now();
        $rows = [];

        foreach ($config['abr'] ?? [] as $state) {
            $uf = State::tryFrom((string) $state['cd']);

            if ($uf === null) {
                continue;
            }

            foreach ($state['mu'] ?? [] as $municipality) {
                $rows[] = [
                    'tse_code' => (string) $municipality['cd'],
                    'ibge_code' => filled($municipality['cdi'] ?? null) ? (string) $municipality['cdi'] : null,
                    'state' => $uf->value,
                    'name' => (string) $municipality['nm'],
                    'is_capital' => ($municipality['c'] ?? 'n') === 's',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // All or nothing: a partial import would never be completed, since
        // the import only runs again when municipalities are missing.
        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                Municipality::query()->upsert($chunk, ['tse_code'], ['ibge_code', 'state', 'name', 'is_capital', 'updated_at']);
            }
        });

        return count($rows);
    }

    /**
     * States where the office is disputed in the election: in a runoff, only
     * those where nobody was elected in the first round (when it is synced),
     * so the TSE is not asked every time for files that do not exist.
     *
     * @return list<State>
     */
    private function disputedStates(int $electionCode, Office $office): array
    {
        $firstRound = $office->electionCode(1);

        if ($office->isNationwide() || $firstRound === null || $firstRound === $electionCode) {
            return $office->states();
        }

        $undecided = Result::query()
            ->forElection($firstRound, $office, Result::SCOPE_STATE)
            ->get(['scope_code', 'elected_candidate_ids']);

        if ($undecided->isEmpty()) {
            return $office->states();
        }

        $codes = $undecided->filter(fn (Result $result): bool => empty($result->elected_candidate_ids))->pluck('scope_code')->all();

        return array_values(array_filter($office->states(), fn (State $state): bool => in_array($state->value, $codes, true)));
    }

    /**
     * @param  list<State>  $states
     */
    private function syncStates(int $electionCode, Office $office, array $states): int
    {
        $urls = collect($states)
            ->mapWithKeys(fn (State $state): array => [$state->value => $this->client->stateResultUrl($electionCode, $office, $state)])
            ->all();

        // A missing file means the office has no result there (no runoff in
        // that state, or not published yet).
        $payloads = collect($this->client->fetchMany($urls))
            ->filter()
            ->map(fn (array $json): ResultPayload => ResultPayload::fromTse($json))
            ->all();

        foreach ($payloads as $payload) {
            $this->allFinal = $this->allFinal && $payload->isFinal;
        }

        if (! $office->isNationwide()) {
            foreach ($payloads as $uf => $payload) {
                $this->storeCandidates($office, State::from($uf), $payload);
            }
        }

        $this->storeResults($electionCode, $office, Result::SCOPE_STATE, $payloads);

        return count($payloads);
    }

    /**
     * @param  list<Office>  $offices
     * @param  array<string, list<State>>  $disputedStates  keyed by office
     */
    private function syncMunicipalities(int $electionCode, array $offices, array $disputedStates, bool $full): int
    {
        if ($offices === []) {
            return 0;
        }

        $states = [];

        foreach ($offices as $office) {
            foreach ($disputedStates[$office->value] as $state) {
                if (! in_array($state, $states, true)) {
                    $states[] = $state;
                }
            }
        }

        [$progress, $reported] = $this->municipalityProgress($electionCode, $states);

        if (Municipality::query()->count() < count($reported)) {
            $this->importMunicipalities($electionCode);
        }

        $synced = 0;

        foreach ($offices as $office) {
            $outdated = $this->outdatedMunicipalities($electionCode, $office, $disputedStates[$office->value], $progress, $full);

            foreach (array_chunk($outdated, 300, preserve_keys: true) as $chunk) {
                $urls = [];

                foreach ($chunk as $tseCode => $state) {
                    $urls[$tseCode] = $this->client->municipalityResultUrl($electionCode, $office, $state, (string) $tseCode);
                }

                $payloads = collect($this->client->fetchMany($urls))
                    ->filter()
                    ->map(fn (array $json): ResultPayload => ResultPayload::fromTse($json))
                    ->all();

                $this->storeResults($electionCode, $office, Result::SCOPE_MUNICIPALITY, $payloads);

                $synced += count($payloads);
            }
        }

        return $synced;
    }

    /**
     * Counting progress of every municipality with sections counted, and
     * every municipality code the TSE reports.
     *
     * @param  list<State>  $states
     * @return array{0: array<string, array{state: State, updated_at: string|null, counted: bool}>, 1: list<string>}
     */
    private function municipalityProgress(int $electionCode, array $states): array
    {
        $urls = collect($states)
            ->mapWithKeys(fn (State $state): array => [$state->value => $this->client->stateProgressUrl($electionCode, $state)])
            ->all();

        $progress = [];
        $reported = [];

        foreach ($this->client->fetchMany($urls) as $uf => $file) {
            foreach ($file['abr'] ?? [] as $municipality) {
                if (($municipality['tpabr'] ?? null) !== 'mun') {
                    continue;
                }

                $tseCode = (string) $municipality['cdabr'];
                $reported[] = $tseCode;
                $progress[$tseCode] = [
                    'state' => State::from($uf),
                    'updated_at' => ResultPayload::dateTime($municipality['dt'] ?? null, $municipality['ht'] ?? null)?->format('Y-m-d H:i:s'),
                    'counted' => (int) ($municipality['s']['st'] ?? 0) > 0,
                ];
            }
        }

        return [$progress, $reported];
    }

    /**
     * Municipalities whose stored result of an office is older than what the
     * TSE reports.
     *
     * @param  list<State>  $states
     * @param  array<string, array{state: State, updated_at: string|null, counted: bool}>  $progress
     * @return array<string, State>
     */
    private function outdatedMunicipalities(int $electionCode, Office $office, array $states, array $progress, bool $full): array
    {
        $storedUpdates = $this->storedUpdates($electionCode, $office, Result::SCOPE_MUNICIPALITY);

        $outdated = [];

        foreach ($progress as $tseCode => $municipality) {
            $tseCode = (string) $tseCode;

            if (! in_array($municipality['state'], $states, true)) {
                continue;
            }

            if (! $municipality['counted'] && ! array_key_exists($tseCode, $storedUpdates)) {
                continue;
            }

            if ($full || ($storedUpdates[$tseCode] ?? null) !== $municipality['updated_at']) {
                $outdated[$tseCode] = $municipality['state'];
            }
        }

        return $outdated;
    }

    /**
     * Totalization time of the stored results of a scope, keyed by scope code
     * (numeric codes become integer keys).
     *
     * @param  list<string>|null  $scopeCodes  only these places
     * @return array<array-key, string|null>
     */
    private function storedUpdates(int $electionCode, Office $office, string $scope, ?array $scopeCodes = null): array
    {
        return Result::query()
            ->forElection($electionCode, $office, $scope)
            ->when($scopeCodes !== null, fn ($query) => $query->whereIn('scope_code', $scopeCodes))
            ->pluck('tse_updated_at', 'scope_code')
            ->map(fn (?string $updatedAt): ?string => $updatedAt === null ? null : Carbon::parse($updatedAt)->format('Y-m-d H:i:s'))
            ->all();
    }

    private function storeCandidates(Office $office, ?State $state, ResultPayload $payload): void
    {
        $now = now();

        $rows = collect($payload->candidates)
            ->map(fn (array $candidate): array => [
                ...collect($candidate)->except(['votes', 'elected'])->all(),
                'office' => $office->value,
                'state' => $state?->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Candidate::query()->upsert($chunk, ['tse_sequence'], ['office', 'state', 'number', 'ballot_name', 'full_name', 'party', 'updated_at']);
        }

        $this->rememberCandidateIds(array_column($payload->candidates, 'tse_sequence'));
    }

    /**
     * Load the ids of the given candidates that are not known yet.
     *
     * @param  list<string>  $sequences
     */
    private function rememberCandidateIds(array $sequences): void
    {
        $missing = array_values(array_diff($sequences, array_keys($this->candidateIds)));

        foreach (array_chunk($missing, 500) as $chunk) {
            $this->candidateIds += Candidate::query()->whereIn('tse_sequence', $chunk)->pluck('id', 'tse_sequence')->all();
        }
    }

    /**
     * @param  array<string, ResultPayload>  $payloads  keyed by scope code
     */
    private function storeResults(int $electionCode, Office $office, string $scope, array $payloads): void
    {
        if ($payloads !== [] && ! $this->full) {
            $storedUpdates = $this->storedUpdates($electionCode, $office, $scope, array_map(strval(...), array_keys($payloads)));

            $payloads = array_filter(
                $payloads,
                fn (ResultPayload $payload, int|string $scopeCode): bool => $payload->updatedAt === null
                    || ($storedUpdates[$scopeCode] ?? null) !== $payload->updatedAt->format('Y-m-d H:i:s'),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        if ($payloads === []) {
            return;
        }

        $this->changed = true;

        $now = now();
        $rows = [];

        foreach ($payloads as $scopeCode => $payload) {
            $this->rememberCandidateIds(array_column($payload->candidates, 'tse_sequence'));
            $votes = [];
            $elected = [];

            foreach ($payload->candidates as $candidate) {
                $candidateId = $this->candidateIds[$candidate['tse_sequence']] ?? null;

                if ($candidateId === null) {
                    continue;
                }

                $votes[$candidateId] = $candidate['votes'];

                if ($candidate['elected']) {
                    $elected[] = $candidateId;
                }
            }

            arsort($votes);
            $leaderId = array_key_first($votes);

            $rows[] = [
                'election_code' => $electionCode,
                'office' => $office->value,
                'scope' => $scope,
                'scope_code' => (string) $scopeCode,
                'sections_total' => $payload->sectionsTotal,
                'sections_counted' => $payload->sectionsCounted,
                'counted_percent' => $payload->countedPercent,
                'electorate' => $payload->electorate,
                'electorate_counted' => $payload->electorateCounted,
                'turnout' => $payload->turnout,
                'abstentions' => $payload->abstentions,
                'valid_votes' => $payload->validVotes,
                'blank_votes' => $payload->blankVotes,
                'null_votes' => $payload->nullVotes,
                'votes' => json_encode((object) $votes, JSON_THROW_ON_ERROR),
                'seats' => $payload->seats,
                'leading_candidate_id' => $leaderId !== null && $votes[$leaderId] > 0 ? $leaderId : null,
                'elected_candidate_ids' => $elected === [] ? null : json_encode($elected, JSON_THROW_ON_ERROR),
                'is_final' => $payload->isFinal,
                'tse_updated_at' => $payload->updatedAt?->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                Result::query()->upsert($chunk, ['election_code', 'office', 'scope', 'scope_code'], [
                    'sections_total', 'sections_counted', 'counted_percent', 'electorate', 'electorate_counted',
                    'turnout', 'abstentions', 'valid_votes', 'blank_votes', 'null_votes', 'votes', 'seats',
                    'leading_candidate_id', 'elected_candidate_ids', 'is_final', 'tse_updated_at', 'updated_at',
                ]);
            }
        });
    }
}
