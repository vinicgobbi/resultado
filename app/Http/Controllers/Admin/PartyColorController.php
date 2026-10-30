<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePartyColorRequest;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\PartyColor;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PartyColorController extends Controller
{
    /**
     * The parties of the synchronized candidates, with their default color
     * (config/partidos.php) and the one chosen here.
     */
    public function index(): Response
    {
        $chosen = PartyColor::chosen();

        return Inertia::render('admin/parties/index', [
            'parties' => Candidate::query()
                ->selectRaw('upper(trim(party)) as party, count(*) as candidates')
                ->groupByRaw('upper(trim(party))')
                ->orderByRaw('upper(trim(party))')
                ->toBase()
                ->get()
                ->map(fn (object $row): array => [
                    'party' => (string) $row->party,
                    'candidates' => (int) $row->candidates,
                    'default_color' => Candidate::defaultPartyColor((string) $row->party),
                    'custom_color' => $chosen[(string) $row->party] ?? null,
                ])
                ->all(),
        ]);
    }

    /**
     * Choose the color of a party, or go back to the default without one.
     * Every election shows it, so all of their cached results are redone.
     */
    public function update(UpdatePartyColorRequest $request): RedirectResponse
    {
        $party = mb_strtoupper(trim($request->string('party')->toString()));

        if ($request->filled('color')) {
            PartyColor::query()->updateOrCreate(['party' => $party], ['color' => strtolower($request->string('color')->toString())]);
        } else {
            PartyColor::query()->where('party', $party)->first()?->delete();
        }

        Election::query()->pluck('tse_code')->each(fn (int $code) => ResultSynchronizer::invalidate($code));

        return back()->with('success', "Cor do {$party} atualizada.");
    }
}
