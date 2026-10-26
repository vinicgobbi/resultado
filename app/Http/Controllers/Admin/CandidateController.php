<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Office;
use App\Enums\State;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Services\ResultsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    /**
     * List the candidates of an office (and state) with their configured colors.
     */
    public function index(Request $request): Response
    {
        $request->validate([
            'cargo' => ['nullable', Rule::enum(Office::class)],
            'uf' => ['nullable', Rule::enum(State::class)],
        ]);

        $office = $request->enum('cargo', Office::class) ?? Office::President;
        $state = $office->isNationwide() ? null : ($request->enum('uf', State::class) ?? State::SaoPaulo);

        return Inertia::render('admin/candidates/index', [
            'office' => $office->value,
            'offices' => collect(Office::cases())
                ->map(fn (Office $option): array => ['value' => $option->value, 'label' => $option->label()]),
            'state' => $state?->value,
            'states' => collect($office->isNationwide() ? [] : $office->states())
                ->map(fn (State $option): array => ['value' => $option->value, 'label' => $option->label()])
                ->values(),
            'candidates' => Candidate::query()
                ->where('office', $office)
                ->where('state', $state)
                ->orderBy('number')
                ->get()
                ->map(fn (Candidate $candidate): array => [
                    ...$candidate->toPublicArray(),
                    'custom_color' => $candidate->color,
                    'party_color' => $candidate->partyColor(),
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
