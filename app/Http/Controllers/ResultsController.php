<?php

namespace App\Http\Controllers;

use App\Enums\Office;
use App\Enums\State;
use App\Services\MapGeometryBuilder;
use App\Services\ResultsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function __construct(private readonly ResultsPresenter $presenter) {}

    /**
     * Live results page of an office: the place where it is elected, the
     * states (president) and the map of municipalities.
     */
    public function index(Request $request): Response
    {
        $round = $this->round($request);
        $office = $this->office($request, $round);
        $electionCode = (int) $office->electionCode($round);
        $places = $office->isNationwide() ? [] : $this->presenter->places($electionCode, $office);
        $state = $office->isNationwide() ? null : $this->state($request, $places);
        $summary = $this->presenter->summary($electionCode, $office, $state);

        return Inertia::render('results/index', [
            'round' => $round,
            'rounds' => ResultsPresenter::rounds(),
            'office' => $office->value,
            'offices' => collect(Office::forRound($round))
                ->map(fn (Office $option): array => ['value' => $option->value, 'label' => $option->label($state)])
                ->values(),
            'state' => $state?->abbreviation(),
            'places' => $places,
            'candidates' => $this->presenter->publicCandidates($summary, $office),
            'summary' => $summary,
            'states' => $office->isNationwide() ? $this->presenter->states($electionCode, $office) : [],
            'mapAvailable' => $office->hasMunicipalityResults() && Storage::exists(MapGeometryBuilder::PATH),
            'map' => Inertia::defer(fn (): array => $office->hasMunicipalityResults() && ($office->isNationwide() || $state !== null)
                ? $this->presenter->map($electionCode, $office, $state)
                : []),
        ]);
    }

    /**
     * Compact national presidential summary, used by the service worker
     * notifications.
     */
    public function summary(Request $request): JsonResponse
    {
        $round = $this->round($request);
        $summary = $this->presenter->summary((int) Office::President->electionCode($round), Office::President);
        $candidates = $this->presenter->candidates($summary);
        $winner = $summary['winners'][0] ?? null;

        return response()->json([
            'round' => $round,
            'counted_percent' => $summary['counted_percent'] ?? 0,
            'is_final' => $summary['is_final'] ?? false,
            'updated_at' => $summary['updated_at'] ?? null,
            'winner' => $winner === null ? null : [
                'name' => $candidates->get($winner['candidate_id'])?->ballot_name,
                'status' => $winner['status'],
            ],
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
            'cargo' => ['nullable', Rule::enum(Office::class)],
            'uf' => ['nullable', 'string', 'size:2'],
        ]);

        return $request->integer('turno', (int) config('eleicoes.turno_ativo'));
    }

    /**
     * Requested office, or president when it is not disputed in the round.
     */
    private function office(Request $request, int $round): Office
    {
        $office = $request->enum('cargo', Office::class) ?? Office::President;

        return in_array($office, Office::forRound($round), true) ? $office : Office::President;
    }

    /**
     * Requested state, when it has results. Without one, the visitor picks
     * the state before seeing anything.
     *
     * @param  list<array{code: string}>  $places
     */
    private function state(Request $request, array $places): ?State
    {
        $requested = strtoupper($request->string('uf')->toString());

        return in_array($requested, array_column($places, 'code'), true) ? State::from(strtolower($requested)) : null;
    }
}
