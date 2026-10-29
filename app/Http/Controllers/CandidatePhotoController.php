<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Election;
use App\Services\Tse\CandidatePhotos;
use App\Services\Tse\RateLimitedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidatePhotoController extends Controller
{
    /**
     * Downloads from the TSE per second triggered by visitors; a page full of
     * deputies would otherwise request dozens of photos at once.
     */
    private const int DOWNLOADS_PER_SECOND = 8;

    /**
     * Downloads per minute triggered by one visitor: enough for a page of
     * deputies, not for walking every candidate to saturate the limit above.
     */
    private const int DOWNLOADS_PER_VISITOR_MINUTE = 60;

    /**
     * While an election is counted, the photos of other elections wait this
     * long: a 429 caused by them would also pause the results.
     */
    private const int RETRY_AFTER_COUNT_SECONDS = 1800;

    /**
     * Official photo of a candidate, downloaded from the TSE the first time
     * it is requested (or beforehand with "php artisan tse:fotos"). Later
     * pages link to the stored file, served without the application.
     */
    public function __invoke(Request $request, Candidate $candidate, CandidatePhotos $photos): BinaryFileResponse
    {
        if (! $photos->isStored($candidate)) {
            abort_if($photos->isMissing($candidate), 404);

            $retryLater = fn (int $seconds) => abort(503, 'Foto temporariamente indisponível.', ['Retry-After' => (string) $seconds]);
            $visitorKey = 'tse-fotos-visitante:'.$request->ip();

            if (! $this->downloadsDuringCount($candidate)) {
                $retryLater(self::RETRY_AFTER_COUNT_SECONDS);
            }

            if (RateLimiter::tooManyAttempts($visitorKey, self::DOWNLOADS_PER_VISITOR_MINUTE)) {
                $retryLater(RateLimiter::availableIn($visitorKey));
            }

            if (RateLimiter::tooManyAttempts('tse-fotos', self::DOWNLOADS_PER_SECOND)) {
                $retryLater(1);
            }

            RateLimiter::hit($visitorKey, 60);
            RateLimiter::hit('tse-fotos', 1);

            try {
                $photos->download([$candidate]);
            } catch (RateLimitedException $exception) {
                $retryLater(max(1, (int) now()->diffInSeconds($exception->pausedUntil)));
            }

            abort_if($photos->isMissing($candidate), 404);
            abort_unless($photos->isStored($candidate), 503, 'Foto temporariamente indisponível.', ['Retry-After' => '30']);
        }

        return response()->file(Storage::disk(Candidate::PHOTO_DISK)->path(Candidate::photoPath($candidate->tse_sequence)), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Whether its photo may be downloaded now: while an election is counted,
     * only the candidates of that election are.
     */
    private function downloadsDuringCount(Candidate $candidate): bool
    {
        $live = Election::query()->live()->get();

        return $live->isEmpty() || $live->contains(fn (Election $election): bool => $election->firstRound()?->tse_code === $candidate->election_code);
    }
}
