<?php

namespace App\Services\Tse;

use App\Enums\State;
use App\Models\Candidate;
use App\Models\Municipality;
use App\Models\Result;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Downloads presidential results from the TSE and stores them locally.
 *
 * Municipality files are only downloaded when the per-state progress file
 * ("-ab.json") reports a newer totalization time than the stored one, so a
 * sync during the count only fetches what actually changed.
 */
class ResultSynchronizer
{
    /**
     * Candidate ids keyed by TSE sequence number.
     *
     * @var array<string, int>
     */
    private array $candidateIds = [];

    public function __construct(private readonly TseClient $client) {}

    /**
     * @return array{states: int, municipalities: int, counted_percent: float, country_changed: bool}
     */
    public function sync(int $electionCode, bool $full = false): array
    {
        if ($full || Municipality::query()->doesntExist()) {
            $this->importMunicipalities($electionCode);
        }

        $country = ResultPayload::fromTse($this->client->fetch($this->client->countryResultUrl($electionCode)));

        $previousUpdate = Result::query()
            ->forElection($electionCode, Result::SCOPE_COUNTRY)
            ->value('tse_updated_at');

        $countryChanged = $previousUpdate === null
            || Carbon::parse($previousUpdate)->format('Y-m-d H:i:s') !== $country->updatedAt?->format('Y-m-d H:i:s');

        $this->storeCandidates($country);
        $this->storeResults($electionCode, Result::SCOPE_COUNTRY, ['br' => $country]);

        $states = $this->syncStates($electionCode);
        $municipalities = $this->syncMunicipalities($electionCode, $full);

        Cache::forget(self::cacheKey($electionCode));

        return [
            'states' => $states,
            'municipalities' => $municipalities,
            'counted_percent' => $country->countedPercent,
            'country_changed' => $countryChanged,
        ];
    }

    /**
     * Cache key used by readers of the results of an election.
     */
    public static function cacheKey(int $electionCode): string
    {
        return "eleicoes.resultados.{$electionCode}";
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

        foreach (array_chunk($rows, 500) as $chunk) {
            Municipality::query()->upsert($chunk, ['tse_code'], ['ibge_code', 'state', 'name', 'is_capital', 'updated_at']);
        }

        return count($rows);
    }

    private function syncStates(int $electionCode): int
    {
        $urls = collect(State::cases())
            ->mapWithKeys(fn (State $state): array => [$state->value => $this->client->stateResultUrl($electionCode, $state)])
            ->all();

        $payloads = collect($this->client->fetchMany($urls))
            ->filter()
            ->map(fn (array $json): ResultPayload => ResultPayload::fromTse($json))
            ->all();

        $this->storeResults($electionCode, Result::SCOPE_STATE, $payloads);

        return count($payloads);
    }

    private function syncMunicipalities(int $electionCode, bool $full): int
    {
        $outdated = $this->outdatedMunicipalities($electionCode, $full);
        $synced = 0;

        foreach (array_chunk($outdated, 300, preserve_keys: true) as $chunk) {
            $urls = [];

            foreach ($chunk as $tseCode => $state) {
                $urls[$tseCode] = $this->client->municipalityResultUrl($electionCode, $state, (string) $tseCode);
            }

            $payloads = collect($this->client->fetchMany($urls))
                ->filter()
                ->map(fn (array $json): ResultPayload => ResultPayload::fromTse($json))
                ->all();

            $this->storeResults($electionCode, Result::SCOPE_MUNICIPALITY, $payloads);

            $synced += count($payloads);
        }

        return $synced;
    }

    /**
     * Municipalities whose stored result is older than what the TSE reports.
     *
     * @return array<string, State> keyed by TSE municipality code
     */
    private function outdatedMunicipalities(int $electionCode, bool $full): array
    {
        $urls = collect(State::cases())
            ->mapWithKeys(fn (State $state): array => [$state->value => $this->client->stateProgressUrl($electionCode, $state)])
            ->all();

        $storedUpdates = Result::query()
            ->forElection($electionCode, Result::SCOPE_MUNICIPALITY)
            ->pluck('tse_updated_at', 'scope_code')
            ->map(fn (?string $updatedAt): ?string => $updatedAt === null ? null : Carbon::parse($updatedAt)->format('Y-m-d H:i:s'));

        $outdated = [];

        foreach ($this->client->fetchMany($urls) as $uf => $progress) {
            foreach ($progress['abr'] ?? [] as $municipality) {
                if (($municipality['tpabr'] ?? null) !== 'mun') {
                    continue;
                }

                $tseCode = (string) $municipality['cdabr'];
                $sectionsCounted = (int) ($municipality['s']['st'] ?? 0);
                $updatedAt = ResultPayload::dateTime($municipality['dt'] ?? null, $municipality['ht'] ?? null)?->format('Y-m-d H:i:s');

                if ($sectionsCounted === 0 && ! $storedUpdates->has($tseCode)) {
                    continue;
                }

                if ($full || $storedUpdates->get($tseCode) !== $updatedAt) {
                    $outdated[$tseCode] = State::from($uf);
                }
            }
        }

        return $outdated;
    }

    private function storeCandidates(ResultPayload $payload): void
    {
        $now = now();

        $rows = collect($payload->candidates)
            ->map(fn (array $candidate): array => [
                ...collect($candidate)->except('votes')->all(),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        Candidate::query()->upsert($rows, ['tse_sequence'], ['number', 'ballot_name', 'full_name', 'party', 'updated_at']);

        $this->candidateIds = Candidate::query()->pluck('id', 'tse_sequence')->all();
    }

    /**
     * @param  array<string, ResultPayload>  $payloads  keyed by scope code
     */
    private function storeResults(int $electionCode, string $scope, array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        if ($this->candidateIds === []) {
            $this->candidateIds = Candidate::query()->pluck('id', 'tse_sequence')->all();
        }

        $now = now();
        $rows = [];

        foreach ($payloads as $scopeCode => $payload) {
            $votes = [];

            foreach ($payload->candidates as $candidate) {
                $candidateId = $this->candidateIds[$candidate['tse_sequence']] ?? null;

                if ($candidateId !== null) {
                    $votes[$candidateId] = $candidate['votes'];
                }
            }

            arsort($votes);
            $leaderId = array_key_first($votes);

            $rows[] = [
                'election_code' => $electionCode,
                'scope' => $scope,
                'scope_code' => (string) $scopeCode,
                'sections_total' => $payload->sectionsTotal,
                'sections_counted' => $payload->sectionsCounted,
                'counted_percent' => $payload->countedPercent,
                'electorate' => $payload->electorate,
                'turnout' => $payload->turnout,
                'abstentions' => $payload->abstentions,
                'valid_votes' => $payload->validVotes,
                'blank_votes' => $payload->blankVotes,
                'null_votes' => $payload->nullVotes,
                'votes' => json_encode((object) $votes, JSON_THROW_ON_ERROR),
                'leading_candidate_id' => $leaderId !== null && $votes[$leaderId] > 0 ? $leaderId : null,
                'is_final' => $payload->isFinal,
                'tse_updated_at' => $payload->updatedAt?->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                Result::query()->upsert($chunk, ['election_code', 'scope', 'scope_code'], [
                    'sections_total', 'sections_counted', 'counted_percent', 'electorate', 'turnout',
                    'abstentions', 'valid_votes', 'blank_votes', 'null_votes', 'votes',
                    'leading_candidate_id', 'is_final', 'tse_updated_at', 'updated_at',
                ]);
            }
        });
    }
}
