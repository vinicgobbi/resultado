<?php

namespace App\Services\Tse;

use App\Models\Candidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Official candidate photos, downloaded from the TSE once and kept in storage.
 */
class CandidatePhotos
{
    public function __construct(private readonly TseClient $client) {}

    public function isStored(Candidate $candidate): bool
    {
        return Storage::disk(Candidate::PHOTO_DISK)->exists(Candidate::photoPath($candidate->tse_sequence));
    }

    /**
     * Whether the TSE recently answered that it has no photo of the candidate.
     */
    public function isMissing(Candidate $candidate): bool
    {
        return Cache::has(self::missingKey($candidate));
    }

    /**
     * The candidates whose photo is neither stored nor known to be missing,
     * checking the "missing" marks of all of them in one cache read.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return Collection<int, Candidate>
     */
    public function pending(Collection $candidates): Collection
    {
        $missing = Cache::many($candidates->map(fn (Candidate $candidate): string => self::missingKey($candidate))->values()->all());

        return $candidates
            ->reject(fn (Candidate $candidate): bool => ($missing[self::missingKey($candidate)] ?? null) !== null || $this->isStored($candidate))
            ->values();
    }

    /**
     * How long a "not found" holds: the TSE may still publish a photo during
     * the count, hardly after the election is over.
     */
    private const int MISSING_DAYS = 1;

    private const int MISSING_DAYS_AFTER_ELECTION = 30;

    /**
     * Download the photos not stored yet. Only a "not found" marks a photo as
     * missing (for a while); other failures are retried on the next call.
     *
     * @param  iterable<Candidate>  $candidates
     * @return array{downloaded: int, missing: int, failed: int}
     *
     * @throws RateLimitedException
     */
    public function download(iterable $candidates): array
    {
        $pending = [];
        $urls = [];

        foreach ($candidates as $candidate) {
            [$election, $directory] = $candidate->photoSource();

            if ($election === null || $this->isStored($candidate) || $this->isMissing($candidate)) {
                continue;
            }

            // A prefix keeps the keys strings (numeric strings become ints).
            $key = "candidato-{$candidate->id}";
            $pending[$key] = $candidate;
            $urls[$key] = $this->client->candidatePhotoUrl($election, $directory, $candidate->tse_sequence);
        }

        $totals = ['downloaded' => 0, 'missing' => 0, 'failed' => 0];

        foreach ($this->client->downloadMany($urls) as $key => $photo) {
            $candidate = $pending[$key];

            if ($photo === false) {
                $totals['failed']++;
            } elseif ($photo === null || ! str_starts_with($photo, "\xFF\xD8")) {
                $over = $candidate->election?->completed_at !== null;
                Cache::put(self::missingKey($candidate), true, now()->addDays($over ? self::MISSING_DAYS_AFTER_ELECTION : self::MISSING_DAYS));
                $totals['missing']++;
            } else {
                Storage::disk(Candidate::PHOTO_DISK)->put(Candidate::photoPath($candidate->tse_sequence), $photo);
                $totals['downloaded']++;
            }
        }

        return $totals;
    }

    private static function missingKey(Candidate $candidate): string
    {
        return "candidatos.foto-ausente.{$candidate->id}";
    }
}
