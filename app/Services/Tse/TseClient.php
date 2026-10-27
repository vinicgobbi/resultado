<?php

namespace App\Services\Tse;

use App\Enums\Office;
use App\Enums\State;
use App\Models\Election;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TseClient
{
    /**
     * Cache key holding the timestamp until which the TSE is not requested.
     */
    public const string PAUSE_KEY = 'tse.pausado-ate';

    /**
     * Cache key counting the recent pauses, so repeated blocks pause longer.
     */
    private const string PAUSES_KEY = 'tse.pausas-recentes';

    /**
     * Longest pause, and how long a block counts as recent.
     */
    private const int MAX_PAUSE_SECONDS = 3600;

    private const int RECENT_PAUSES_SECONDS = 7200;

    /**
     * Requests that failed for a reason other than "not found".
     */
    private int $failedRequests = 0;

    /**
     * @param  positive-int  $concurrency
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $environment,
        private readonly int $concurrency,
        private readonly int $pauseSeconds = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('eleicoes.base_url'), '/'),
            environment: (string) config('eleicoes.ambiente'),
            concurrency: max(1, (int) config('eleicoes.concorrencia')),
            pauseSeconds: max(1, (int) config('eleicoes.pausa_429')),
        );
    }

    /**
     * Until when the TSE is not requested after answering 429, if paused.
     */
    public function pausedUntil(): ?Carbon
    {
        $timestamp = Cache::get(self::PAUSE_KEY);

        return $timestamp !== null && $timestamp > now()->getTimestamp() ? Carbon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Number of requests that failed for a reason other than "not found".
     */
    public function failedRequests(): int
    {
        return $this->failedRequests;
    }

    /**
     * Index of every election published by the TSE (current cycles only).
     */
    public function electionsIndexUrl(): string
    {
        return "{$this->baseUrl}/{$this->environment}/comum/config/ele-c.json";
    }

    /**
     * File listing every state and municipality of an election.
     */
    public function municipalitiesConfigUrl(Election $election): string
    {
        return $this->url($election, "config/mun-{$this->electionId($election)}-cm.json");
    }

    /**
     * Results of an office for the whole country.
     */
    public function countryResultUrl(Election $election, Office $office): string
    {
        return $this->url($election, "dados/br/br-c{$office->tseCode()}-{$this->electionId($election)}-u.json");
    }

    /**
     * Results of an office for one state.
     */
    public function stateResultUrl(Election $election, Office $office, State $state): string
    {
        return $this->url($election, "dados/{$state->value}/{$state->value}-c{$office->tseCode($state)}-{$this->electionId($election)}-u.json");
    }

    /**
     * Results of an office for one municipality.
     */
    public function municipalityResultUrl(Election $election, Office $office, State $state, string $tseCode): string
    {
        return $this->url($election, "dados/{$state->value}/{$state->value}{$tseCode}-c{$office->tseCode($state)}-{$this->electionId($election)}-u.json");
    }

    /**
     * Official photo of a candidate; the directory is the state of the
     * office, or "br" for president.
     */
    public function candidatePhotoUrl(Election $election, string $directory, string $sequence): string
    {
        return $this->url($election, "fotos/{$directory}/{$sequence}.jpeg");
    }

    /**
     * Counting progress (and last update time) of every municipality of a state.
     */
    public function stateProgressUrl(Election $election, State $state): string
    {
        return $this->url($election, "dados/{$state->value}/{$state->value}-{$this->electionId($election)}-ab.json");
    }

    /**
     * Fetch a single JSON file.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws RateLimitedException
     */
    public function fetch(string $url): array
    {
        $this->ensureNotPaused();

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->retry([500, 2000, 5000], 0, fn (Throwable $exception): bool => $this->isTransient($exception), throw: false)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->failedRequests++;

            throw $exception;
        }

        if ($response->tooManyRequests()) {
            $this->pause();
        }

        if ($response->failed()) {
            $this->failedRequests++;
        }

        return $response->throw()->json() ?? [];
    }

    /**
     * Fetch several JSON files concurrently. Files that could not be fetched
     * (not published yet, timeouts...) come back as null.
     *
     * @param  array<string, string>  $urls
     * @return array<string, array<string, mixed>|null>
     *
     * @throws RateLimitedException
     */
    public function fetchMany(array $urls): array
    {
        return array_map(
            fn (Response|Throwable|null $response): ?array => $response instanceof Response && $response->successful() ? $response->json() : null,
            $this->requestMany($urls, json: true),
        );
    }

    /**
     * Download several binary files concurrently: the content, null when the
     * file does not exist, or false when it could not be downloaded now.
     *
     * @param  array<string, string>  $urls
     * @return array<string, string|false|null>
     *
     * @throws RateLimitedException
     */
    public function downloadMany(array $urls): array
    {
        return array_map(
            fn (Response|Throwable|null $response): string|false|null => match (true) {
                $response instanceof Response && $response->successful() => $response->body(),
                $response instanceof Response && $response->notFound() => null,
                default => false,
            },
            $this->requestMany($urls, json: false),
        );
    }

    /**
     * Download a binary file: the content, null when it does not exist, or
     * false when it could not be downloaded now.
     *
     * @throws RateLimitedException
     */
    public function download(string $url): string|false|null
    {
        return $this->downloadMany(['file' => $url])['file'];
    }

    /**
     * Send concurrent GET requests, pausing every request to the TSE when
     * any of them is answered with 429.
     *
     * @param  array<string, string>  $urls
     * @return array<string, Response|Throwable|null>
     *
     * @throws RateLimitedException
     */
    private function requestMany(array $urls, bool $json): array
    {
        if ($urls === []) {
            return [];
        }

        $this->ensureNotPaused();

        $responses = Http::pool(fn (Pool $pool): array => collect($urls)
            ->map(fn (string $url, string $key) => $pool->as($key)->connectTimeout(5)->timeout(30)->when($json, fn ($request) => $request->acceptJson())->get($url))
            ->values()
            ->all(), concurrency: $this->concurrency);

        $results = [];
        $rateLimited = false;

        foreach ($urls as $key => $url) {
            $response = $responses[$key] ?? null;
            $results[$key] = $response;

            if ($response instanceof Response && ($response->successful() || $response->notFound())) {
                continue;
            }

            $this->failedRequests++;
            $rateLimited = $rateLimited || ($response instanceof Response && $response->tooManyRequests());

            Log::warning('Falha ao buscar arquivo do TSE.', [
                'url' => $url,
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $response instanceof Throwable ? $response->getMessage() : null,
            ]);
        }

        if ($rateLimited) {
            $this->pause();
        }

        return $results;
    }

    /**
     * @throws RateLimitedException
     */
    private function ensureNotPaused(): void
    {
        $pausedUntil = $this->pausedUntil();

        if ($pausedUntil !== null) {
            throw new RateLimitedException($pausedUntil);
        }
    }

    /**
     * Stop requesting the TSE for a while after it answered 429. The TSE
     * blocks the whole IP (its site included) and is stricter with an IP it
     * blocked recently, so each block in a row doubles the pause.
     *
     * @throws RateLimitedException
     */
    private function pause(): never
    {
        $recentPauses = (int) Cache::get(self::PAUSES_KEY, 0);
        Cache::put(self::PAUSES_KEY, $recentPauses + 1, now()->addSeconds(self::RECENT_PAUSES_SECONDS));

        $pausedUntil = now()->addSeconds(min($this->pauseSeconds * 2 ** $recentPauses, self::MAX_PAUSE_SECONDS));
        Cache::put(self::PAUSE_KEY, $pausedUntil->getTimestamp(), $pausedUntil);

        throw new RateLimitedException(Carbon::instance($pausedUntil));
    }

    private function url(Election $election, string $path): string
    {
        return "{$this->baseUrl}/{$this->environment}/{$election->cycle}/{$election->tse_code}/{$path}";
    }

    private function electionId(Election $election): string
    {
        return 'e'.str_pad((string) $election->tse_code, 6, '0', STR_PAD_LEFT);
    }

    private function isTransient(Throwable $exception): bool
    {
        // A 429 is not retried: insisting only extends the block.
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }
}
