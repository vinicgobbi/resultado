<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\MapGeometryBuilder;
use App\Services\ResultsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-import-type FormattedResult from ResultsPresenter
 */
class ResultsController extends Controller
{
    public function __construct(private readonly ResultsPresenter $presenter) {}

    /**
     * Live results page with the national summary, states and map.
     */
    public function index(Request $request): Response
    {
        $round = $this->round($request);
        $electionCode = ResultsPresenter::electionCode($round);

        return Inertia::render('results/index', [
            'round' => $round,
            'rounds' => ResultsPresenter::rounds(),
            'candidates' => $this->presenter->candidates($electionCode)
                ->map(fn (Candidate $candidate): array => $candidate->toPublicArray())
                ->values(),
            'summary' => $this->presenter->summary($electionCode),
            'states' => $this->presenter->states($electionCode),
            'mapAvailable' => Storage::exists(MapGeometryBuilder::PATH),
            'map' => Inertia::defer(fn (): array => $this->presenter->map($electionCode)),
        ]);
    }

    /**
     * Compact national summary, used by the service worker notifications.
     */
    public function summary(Request $request): JsonResponse
    {
        $round = $this->round($request);
        $electionCode = ResultsPresenter::electionCode($round);
        $summary = $this->presenter->summary($electionCode);
        $candidates = $this->presenter->candidates($electionCode);

        return response()->json([
            'round' => $round,
            'counted_percent' => $summary['counted_percent'] ?? 0,
            'is_final' => $summary['is_final'] ?? false,
            'updated_at' => $summary['updated_at'] ?? null,
            'candidates' => collect($summary['results'] ?? [])
                ->take(4)
                ->map(fn (array $result): array => [
                    'name' => $candidates->get($result['candidate_id'])?->ballot_name,
                    'percent' => $result['percent'],
                ])
                ->values(),
        ])->header('Cache-Control', 'no-store');
    }

    private function round(Request $request): int
    {
        $request->validate([
            'turno' => ['nullable', Rule::in(ResultsPresenter::rounds())],
        ]);

        return $request->integer('turno', (int) config('eleicoes.turno_ativo'));
    }
}
