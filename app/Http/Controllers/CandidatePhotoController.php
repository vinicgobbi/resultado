<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\Tse\CandidatePhotos;
use App\Services\Tse\RateLimitedException;
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
     * Official photo of a candidate, downloaded from the TSE the first time
     * it is requested (or beforehand with "php artisan tse:fotos").
     */
    public function __invoke(Candidate $candidate, CandidatePhotos $photos): BinaryFileResponse
    {
        if (! $photos->isStored($candidate)) {
            abort_if($photos->isMissing($candidate), 404);

            $retryLater = fn (int $seconds) => abort(503, 'Foto temporariamente indisponível.', ['Retry-After' => (string) $seconds]);

            if (RateLimiter::tooManyAttempts('tse-fotos', self::DOWNLOADS_PER_SECOND)) {
                $retryLater(1);
            }

            RateLimiter::hit('tse-fotos', 1);

            try {
                $photos->download([$candidate]);
            } catch (RateLimitedException $exception) {
                $retryLater(max(1, (int) now()->diffInSeconds($exception->pausedUntil)));
            }

            abort_if($photos->isMissing($candidate), 404);
            abort_unless($photos->isStored($candidate), 503, 'Foto temporariamente indisponível.', ['Retry-After' => '30']);
        }

        return response()->file(Storage::path(Candidate::photoPath($candidate->tse_sequence)), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
