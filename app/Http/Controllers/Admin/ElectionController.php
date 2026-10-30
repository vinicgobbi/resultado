<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateElectionCycleRequest;
use App\Models\Election;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ElectionController extends Controller
{
    /**
     * Every election year, hidden ones included, with what the public sees
     * of it and its elections as published by the TSE.
     */
    public function index(): Response
    {
        return Inertia::render('admin/elections/index', [
            'cycles' => array_values(Election::query()
                ->get()
                ->groupBy('cycle')
                ->map(function (Collection $elections): array {
                    $elections = $elections
                        ->sortBy(fn (Election $election): array => [$election->round, $election->type->order()])
                        ->values();
                    /** @var Election $first */
                    $first = $elections->first();

                    return [
                        'cycle' => $first->cycle,
                        'year' => $first->date->year,
                        'display_name' => $first->display_name,
                        'name' => $first->cycleName(),
                        'is_hidden' => $first->is_hidden,
                        'is_featured' => $first->is_featured,
                        'elections' => $elections->map(fn (Election $election): array => [
                            'code' => $election->tse_code,
                            'name' => $election->name,
                            'round' => $election->round,
                            'date' => $election->date->toDateString(),
                            'status' => $election->status(),
                            'completed_at' => $election->completed_at?->toIso8601String(),
                        ])->all(),
                    ];
                })
                ->sortByDesc('year')
                ->all()),
        ]);
    }

    /**
     * Name a year on the site, or hide it from the public archive (and from
     * the home page, so it stops being the featured one).
     */
    public function update(UpdateElectionCycleRequest $request, string $cycle): RedirectResponse
    {
        $elections = Election::query()->where('cycle', $cycle);
        abort_unless($elections->exists(), 404);

        $hidden = $request->boolean('is_hidden');
        $name = trim($request->string('display_name')->toString());

        $elections->update([
            'display_name' => $name === '' ? null : $name,
            'is_hidden' => $hidden,
            ...($hidden ? ['is_featured' => false] : []),
        ]);

        return back()->with('success', 'Eleição atualizada.');
    }

    /**
     * Choose the year shown on the home page, or go back to choosing it by
     * itself (the one being counted, otherwise the latest).
     */
    public function feature(Request $request): RedirectResponse
    {
        $request->validate(['cycle' => ['nullable', 'string', 'exists:elections,cycle']]);

        $cycle = $request->string('cycle')->toString();

        Election::query()->where('is_featured', true)->update(['is_featured' => false]);

        if ($cycle !== '') {
            Election::query()->where('cycle', $cycle)->where('is_hidden', false)->update(['is_featured' => true]);
        }

        return back()->with('success', $cycle === ''
            ? 'A página inicial volta a mostrar a eleição em apuração ou a mais recente.'
            : 'Eleição escolhida para a página inicial.');
    }

    /**
     * Sync a completed election again: the count of the election days, or
     * the hourly import of past elections, downloads it once more until
     * every result is final again.
     */
    public function reopen(Election $election): RedirectResponse
    {
        $election->update(['completed_at' => null]);
        ResultSynchronizer::invalidate($election->tse_code);

        return back()->with('success', "{$election->name}: a sincronização foi reaberta. Ela roda na próxima importação (até 1 hora) ou com \"Ressincronizar\" no painel.");
    }
}
