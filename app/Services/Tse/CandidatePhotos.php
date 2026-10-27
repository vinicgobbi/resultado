<?php

namespace App\Services\Tse;

use App\Models\Candidate;
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
     * Download the photos not stored yet. Only a "not found" marks a photo as
     * missing (for a day); other failures are retried on the next call.
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
            [$electionCode, $directory] = $candidate->photoSource();

            if ($electionCode === null || $this->isStored($candidate) || $this->isMissing($candidate)) {
                continue;
            }

            // A prefix keeps the keys strings (numeric strings become ints).
            $key = "candidato-{$candidate->id}";
            $pending[$key] = $candidate;
            $urls[$key] = $this->client->candidatePhotoUrl($electionCode, $directory, $candidate->tse_sequence);
        }

        $totals = ['downloaded' => 0, 'missing' => 0, 'failed' => 0];

        foreach ($this->client->downloadMany($urls) as $key => $photo) {
            $candidate = $pending[$key];

            if ($photo === false) {
                $totals['failed']++;
            } elseif ($photo === null || ! str_starts_with($photo, "\xFF\xD8")) {
                Cache::put(self::missingKey($candidate), true, now()->addDay());
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
