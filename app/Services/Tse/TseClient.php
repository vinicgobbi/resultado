<?php

namespace App\Services\Tse;

use App\Enums\State;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TseClient
{
    /**
     * @param  positive-int  $concurrency
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $environment,
        private readonly string $cycle,
        private readonly string $office,
        private readonly int $concurrency,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('eleicoes.base_url'), '/'),
            environment: (string) config('eleicoes.ambiente'),
            cycle: (string) config('eleicoes.ciclo'),
            office: (string) config('eleicoes.cargo'),
            concurrency: max(1, (int) config('eleicoes.concorrencia')),
        );
    }

    /**
     * File listing every state and municipality of an election.
     */
    public function municipalitiesConfigUrl(int $electionCode): string
    {
        return $this->url($electionCode, "config/mun-{$this->electionId($electionCode)}-cm.json");
    }

    /**
     * Presidential results for the whole country.
     */
    public function countryResultUrl(int $electionCode): string
    {
        return $this->url($electionCode, "dados/br/br-c{$this->office}-{$this->electionId($electionCode)}-u.json");
    }

    /**
     * Presidential results for one state.
     */
    public function stateResultUrl(int $electionCode, State $state): string
    {
        return $this->url($electionCode, "dados/{$state->value}/{$state->value}-c{$this->office}-{$this->electionId($electionCode)}-u.json");
    }

    /**
     * Presidential results for one municipality.
     */
    public function municipalityResultUrl(int $electionCode, State $state, string $tseCode): string
    {
        return $this->url($electionCode, "dados/{$state->value}/{$state->value}{$tseCode}-c{$this->office}-{$this->electionId($electionCode)}-u.json");
    }

    /**
     * Counting progress (and last update time) of every municipality of a state.
     */
    public function stateProgressUrl(int $electionCode, State $state): string
    {
        return $this->url($electionCode, "dados/{$state->value}/{$state->value}-{$this->electionId($electionCode)}-ab.json");
    }

    /**
     * Fetch a single JSON file.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function fetch(string $url): array
    {
        return Http::connectTimeout(5)
            ->timeout(30)
            ->retry([200, 1000, 3000], 0, fn (Throwable $exception): bool => $this->isTransient($exception))
            ->acceptJson()
            ->get($url)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Fetch several JSON files concurrently. Files that could not be fetched
     * (not published yet, timeouts...) come back as null.
     *
     * @param  array<string, string>  $urls
     * @return array<string, array<string, mixed>|null>
     */
    public function fetchMany(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool): array => collect($urls)
            ->map(fn (string $url, string $key) => $pool->as($key)->connectTimeout(5)->timeout(30)->acceptJson()->get($url))
            ->values()
            ->all(), concurrency: $this->concurrency);

        $results = [];

        foreach ($urls as $key => $url) {
            $response = $responses[$key] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $results[$key] = $response->json();

                continue;
            }

            if (! ($response instanceof Response && $response->notFound())) {
                Log::warning('Falha ao buscar arquivo do TSE.', [
                    'url' => $url,
                    'status' => $response instanceof Response ? $response->status() : null,
                    'error' => $response instanceof Throwable ? $response->getMessage() : null,
                ]);
            }

            $results[$key] = null;
        }

        return $results;
    }

    private function url(int $electionCode, string $path): string
    {
        return "{$this->baseUrl}/{$this->environment}/{$this->cycle}/{$electionCode}/{$path}";
    }

    private function electionId(int $electionCode): string
    {
        return 'e'.str_pad((string) $electionCode, 6, '0', STR_PAD_LEFT);
    }

    private function isTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError() || $exception->response->status() === 429));
    }
}
