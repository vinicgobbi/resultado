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
use Illuminate\Database\Eloquent\Collection;
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
            'cycles' => Election::cycles(),
            'rounds' => $this->rounds($election, $office, $state, $municipality),
            'office' => $office->value,
            // Where the office is elected: what the visitor has to pick.
            'level' => match (true) {
                $office->isNationwide() => 'country',
                $office->isMunicipal() => 'municipality',
                default => 'state',
            },
            'offices' => $siblings
                ->flatMap(fn (Election $sibling): array => $sibling->offices
                    ->filter(fn (Office $option): bool => $this->offeredIn($option, $state))
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
            'celebrateWinners' => $election->celebratesWinners(),
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
        $runoff = $summary['runoff'] ?? null;

        return response()->json([
            'round' => $election->round ?? 1,
            'counted_percent' => $summary['counted_percent'] ?? 0,
            'is_final' => $summary['is_final'] ?? false,
            'updated_at' => $summary['updated_at'] ?? null,
            'winner' => $winner === null ? null : [
                'name' => $candidates->get($winner['candidate_id'])['name'] ?? null,
                'status' => $winner['status'],
            ],
            'runoff' => $runoff === null ? null : [
                'status' => $runoff['status'],
                'names' => array_map(fn (int $candidateId): ?string => $candidates->get($candidateId)['name'] ?? null, $runoff['candidate_ids']),
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

    /**
     * Rounds of the election's year for the office and place on screen: the
     * election of each one, or why the round does not exist for them (only
     * some offices, states and municipalities have a runoff).
     *
     * @return list<array{round: int, date: string, status: string, code: int|null, unavailable: string|null}>
     */
    private function rounds(Election $election, Office $office, ?State $state, ?Municipality $municipality): array
    {
        $officeLabel = $office->label($state);

        return Election::query()
            ->where('cycle', $election->cycle)
            ->get()
            ->groupBy('round')
            ->sortKeys()
            ->map(function (Collection $elections, int $round) use ($office, $officeLabel, $state, $municipality): array {
                $holder = $elections->first(fn (Election $candidate): bool => $candidate->offices->contains($office));
                $municipalities = $holder?->municipalities();
                /** @var Election $first */
                $first = $holder ?? $elections->sortBy(fn (Election $candidate): int => $candidate->type->order())->first();
                $hadNo = $first->status() === 'agendada' ? 'não terá' : 'não teve';

                $unavailable = match (true) {
                    $holder === null => "{$officeLabel} não tem {$round}º turno.",
                    $state !== null && ! in_array($state, $holder->statesFor($office), true) => "{$state->label()} {$hadNo} {$round}º turno para {$officeLabel}.",
                    $municipality !== null && $municipalities !== null && ! array_key_exists($municipality->tse_code, $municipalities) => "{$municipality->name} {$hadNo} {$round}º turno para {$officeLabel}.",
                    default => null,
                };

                return [
                    'round' => $round,
                    'date' => $first->date->toDateString(),
                    'status' => $first->status(),
                    'code' => $unavailable === null ? $holder?->tse_code : null,
                    'unavailable' => $unavailable,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Whether the tab of an office is shown: an office disputed in a single
     * state (district councillors, in PE) only once that state is chosen.
     */
    private function offeredIn(Office $office, ?State $state): bool
    {
        return $state === null
            ? count($office->states()) > 1
            : in_array($state, $office->states(), true);
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
