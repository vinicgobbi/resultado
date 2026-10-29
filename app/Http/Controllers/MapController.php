<?php

namespace App\Http\Controllers;

use App\Enums\Office;
use App\Models\Election;
use App\Models\Municipality;
use App\Services\MapGeometryBuilder;
use App\Services\ResultsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MapController extends Controller
{
    /**
     * Projected SVG geometry of every municipality and state, plus the
     * municipality names used by the map tooltip. Browsers that already have
     * the current version get an empty "304 Not Modified".
     */
    public function geometry(Request $request): Response
    {
        abort_unless(Storage::exists(MapGeometryBuilder::PATH), 404, 'Mapa ainda não gerado. Rode php artisan mapa:baixar.');

        $version = Storage::lastModified(MapGeometryBuilder::PATH).'-'.Municipality::query()->count();

        $response = (new Response)->setEtag(md5($version))->setPublic()->setMaxAge(3600);

        if ($response->isNotModified($request)) {
            return $response;
        }

        // Kept for a week: a new map or new municipalities change the version.
        $content = Cache::remember("mapa.geometria.{$version}", now()->addWeek(), function (): string {
            $geometry = json_decode((string) Storage::get(MapGeometryBuilder::PATH), true, flags: JSON_THROW_ON_ERROR);

            $geometry['names'] = Municipality::query()
                ->whereNotNull('ibge_code')
                ->get(['ibge_code', 'name', 'state'])
                ->mapWithKeys(fn (Municipality $municipality): array => [
                    $municipality->ibge_code => [$municipality->name, $municipality->state->abbreviation()],
                ])
                ->all();

            return json_encode($geometry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });

        return $response->setContent($content)->header('Content-Type', 'application/json');
    }

    /**
     * Detailed result of one municipality on the map of an office.
     */
    public function municipality(Request $request, Election $election, Municipality $municipality, ResultsPresenter $presenter): JsonResponse
    {
        $request->validate([
            'cargo' => ['nullable', Rule::enum(Office::class)],
        ]);

        $office = $request->enum('cargo', Office::class) ?? $election->offices->first();

        abort_if($office === null || ! $election->offices->contains($office) || ! $office->hasMap(), 404);

        $electionCode = $election->tse_code;

        $result = $presenter->municipality($electionCode, $office, $municipality);
        $ids = array_column($result['results'] ?? [], 'candidate_id');
        $state = $office->isNationwide() ? null : $municipality->state;
        // The colors of the race where the office is elected, as on the map.
        $candidates = $presenter->summary($electionCode, $office, $state) === null
            ? $presenter->publicCandidates($result, $office)
            : $presenter->placeCandidates($electionCode, $office, $state);

        return response()->json([
            'name' => $municipality->name,
            'state' => $municipality->state->abbreviation(),
            'is_capital' => $municipality->is_capital,
            'result' => $result,
            'candidates' => array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => in_array($candidate['id'], $ids, true),
            )),
        ]);
    }
}
