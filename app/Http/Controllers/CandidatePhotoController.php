<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidatePhotoController extends Controller
{
    /**
     * Official photo of a candidate, downloaded from the TSE during the sync.
     */
    public function __invoke(Candidate $candidate): BinaryFileResponse
    {
        abort_unless($candidate->hasPhoto(), 404);

        return response()->file(Storage::path(Candidate::photoPath($candidate->tse_sequence)), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
