<?php

namespace App\Http\Controllers;

use App\Enums\Office;
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
     * municipality names used by the map tooltip.
     */
    public function geometry(): Response
    {
        abort_unless(Storage::exists(MapGeometryBuilder::PATH), 404, 'Mapa ainda não gerado. Rode php artisan mapa:baixar.');

        $version = Storage::lastModified(MapGeometryBuilder::PATH).'-'.Municipality::query()->count();

        $content = Cache::rememberForever("mapa.geometria.{$version}", function (): string {
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

        return response($content, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => '"'.md5($version).'"',
        ]);
    }

    /**
     * Detailed result of one municipality.
     */
    public function municipality(Request $request, Municipality $municipality, ResultsPresenter $presenter): JsonResponse
    {
        $request->validate([
            'turno' => ['nullable', Rule::in(ResultsPresenter::rounds())],
            'cargo' => ['nullable', Rule::enum(Office::class)],
        ]);

        $office = $request->enum('cargo', Office::class) ?? Office::President;
        $electionCode = $office->electionCode($request->integer('turno', (int) config('eleicoes.turno_ativo')));

        abort_if($electionCode === null || ! $office->hasMunicipalityResults(), 404);

        $result = $presenter->municipality($electionCode, $office, $municipality);
        $ids = array_column($result['results'] ?? [], 'candidate_id');
        // The colors of the race where the office is elected, as on the map.
        $place = $presenter->summary($electionCode, $office, $office->isNationwide() ? null : $municipality->state);

        return response()->json([
            'name' => $municipality->name,
            'state' => $municipality->state->abbreviation(),
            'is_capital' => $municipality->is_capital,
            'result' => $result,
            'candidates' => array_values(array_filter(
                $presenter->publicCandidates($place ?? $result, $office),
                fn (array $candidate): bool => in_array($candidate['id'], $ids, true),
            )),
        ]);
    }
}
