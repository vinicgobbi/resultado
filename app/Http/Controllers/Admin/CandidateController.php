<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Services\ResultsPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    /**
     * List candidates with their configured colors.
     */
    public function index(): Response
    {
        return Inertia::render('admin/candidates/index', [
            'candidates' => Candidate::query()
                ->orderBy('number')
                ->get()
                ->map(fn (Candidate $candidate): array => [
                    ...$candidate->toPublicArray(),
                    'custom_color' => $candidate->color,
                ]),
            'activeRound' => (int) config('eleicoes.turno_ativo'),
            'rounds' => ResultsPresenter::rounds(),
        ]);
    }

    /**
     * Update the color of a candidate.
     */
    public function update(UpdateCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $candidate->update([
            'color' => $request->filled('color') ? strtolower($request->string('color')->toString()) : null,
        ]);

        return back()->with('success', "Cor de {$candidate->ballot_name} atualizada.");
    }
}
