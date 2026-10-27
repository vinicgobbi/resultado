<?php

namespace App\Http\Controllers;

use App\Enums\ElectionType;
use App\Enums\Office;
use App\Enums\State;
use App\Models\Election;
use App\Models\Municipality;
use App\Services\MapGeometryBuilder;
use App\Services\ResultsPresenter;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResultsController extends Controller
{
    public function __construct(private readonly ResultsPresenter $presenter) {}

    /**
     * Results of the current election: the one being counted, or the latest.
     * Links of the time there was a single election ("?turno=2") are sent to
     * the federal election of that round.
     */
    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->filled('turno')) {
            return $this->redirectFromRound($request);
        }

        $election = Election::current();

        return $election === null
            ? redirect()->route('elections.index')
            : $this->render($request, $election);
    }

    /**
     * Results of an election.
     */
    public function show(Request $request, Election $election): Response|RedirectResponse
    {
        return $this->render($request, $election);
    }

    /**
     * Live results page of an office: the place where it is elected (chosen
     * by the visitor below the country), the states (president) and the map
     * of municipalities.
     *
     * The page polls only the version, and reloads the results when it
     * changes, so the results are only computed when requested.
     */
    private function render(Request $request, Election $election): Response|RedirectResponse
    {
        $request->validate([
            'cargo' => ['nullable', Rule::enum(Office::class)],
            'uf' => ['nullable', 'string', 'size:2'],
            'municipio' => ['nullable', 'string', 'max:5'],
        ]);

        $siblings = $election->siblings();
        $office = $request->enum('cargo', Office::class);

        // The office of another election of the same day.
        if ($office !== null && ! $election->offices->contains($office)) {
            $holder = $siblings->first(fn (Election $sibling): bool => $sibling->offices->contains($office));

            if ($holder !== null) {
                return redirect()->route('elections.show', [$holder, ...$request->only(['cargo', 'uf', 'municipio'])]);
            }
        }

        $office = $office !== null && $election->offices->contains($office) ? $office : $election->offices->firstOrFail();
        $code = $election->tse_code;
        $places = $office->isNationwide() ? [] : $this->presenter->places($code, $office);
        $state = $office->isNationwide() ? null : $this->state($request, $places);
        $municipalities = $office->isMunicipal() && $state !== null ? $this->presenter->municipalities($code, $office, $state) : [];
        $municipality = $this->municipality($request, $municipalities);
        $hasMap = $office->hasMap() && Storage::exists(MapGeometryBuilder::PATH);

        return Inertia::render('results/index', [
            'election' => $election->toPublicArray(),
            'rounds' => $this->rounds($election),
            'office' => $office->value,
            // Where the office is elected: what the visitor has to pick.
            'level' => match (true) {
                $office->isNationwide() => 'country',
                $office->isMunicipal() => 'municipality',
                default => 'state',
            },
            'offices' => $siblings
                ->flatMap(fn (Election $sibling): array => $sibling->offices
                    ->map(fn (Office $option): array => ['value' => $option->value, 'label' => $option->label($state), 'election' => $sibling->tse_code])
                    ->all())
                ->values(),
            'state' => $state?->abbreviation(),
            'municipality' => $municipality === null ? null : ['code' => $municipality->tse_code, 'name' => $municipality->name],
            'version' => ResultSynchronizer::version($code),
            'places' => $places,
            'municipalities' => $municipalities,
            'proportional' => $office->isProportional(),
            'candidates' => fn (): array => $this->presenter->placeCandidates($code, $office, $state, $municipality),
            'summary' => fn (): ?array => $this->presenter->summary($code, $office, $state, $municipality),
            'states' => fn (): array => $office->isNationwide() ? $this->presenter->states($code, $office) : [],
            'mapAvailable' => $hasMap,
            'map' => Inertia::defer(fn (): array => $hasMap && ($office->isNationwide() || $state !== null)
                ? $this->presenter->map($code, $office, $state)
                : []),
        ]);
    }

    /**
     * Compact national presidential summary, used by the service worker
     * notifications.
     */
    public function summary(): JsonResponse
    {
        $election = Election::current(Office::President);
        $summary = $election === null ? null : $this->presenter->summary($election->tse_code, Office::President);
        $candidates = $election === null
            ? collect()
            : collect($this->presenter->placeCandidates($election->tse_code, Office::President))->keyBy('id');
        $winner = $summary['winners'][0] ?? null;

        return response()->json([
            'round' => $election->round ?? 1,
            'counted_percent' => $summary['counted_percent'] ?? 0,
            'is_final' => $summary['is_final'] ?? false,
            'updated_at' => $summary['updated_at'] ?? null,
            'winner' => $winner === null ? null : [
                'name' => $candidates->get($winner['candidate_id'])['name'] ?? null,
                'status' => $winner['status'],
            ],
            'candidates' => collect($summary['results'] ?? [])
                ->take(4)
                ->map(fn (array $result): array => [
                    'name' => $candidates->get($result['candidate_id'])['name'] ?? null,
                    'percent' => $result['percent'],
                ])
                ->values(),
        ])->header('Cache-Control', 'no-store');
    }

    private function redirectFromRound(Request $request): RedirectResponse
    {
        $election = Election::query()
            ->where('type', ElectionType::Federal)
            ->where('round', $request->integer('turno'))
            ->orderByDesc('date')
            ->first();

        return $election === null
            ? redirect()->route('home')
            : redirect()->route('elections.show', [$election, ...$request->only(['cargo', 'uf'])]);
    }

    /**
     * Rounds of the election, linking each to its own election.
     *
     * @return list<array{round: int, code: int}>
     */
    private function rounds(Election $election): array
    {
        $first = $election->firstRound();

        return array_values(array_map(
            fn (Election $round): array => ['round' => $round->round, 'code' => $round->tse_code],
            array_filter([$first, $first?->secondRound()]),
        ));
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

    /**
     * Requested municipality, when it has results of the office.
     *
     * @param  list<array{code: string}>  $municipalities
     */
    private function municipality(Request $request, array $municipalities): ?Municipality
    {
        $requested = $request->string('municipio')->toString();

        return in_array($requested, array_column($municipalities, 'code'), true)
            ? Municipality::query()->where('tse_code', $requested)->first()
            : null;
    }
}
