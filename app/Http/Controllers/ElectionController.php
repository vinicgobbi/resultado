<?php

namespace App\Http\Controllers;

use App\Enums\Office;
use App\Models\Election;
use Inertia\Inertia;
use Inertia\Response;

class ElectionController extends Controller
{
    /**
     * Every registered election, the most recent first.
     */
    public function index(): Response
    {
        return Inertia::render('elections/index', [
            'elections' => Election::query()
                ->get()
                ->sort(fn (Election $a, Election $b): int => [$b->date->getTimestamp(), $a->round, $a->type->order()] <=> [$a->date->getTimestamp(), $b->round, $b->type->order()])
                ->values()
                ->map(fn (Election $election): array => [
                    ...$election->toPublicArray(),
                    'offices' => $election->offices->map(fn (Office $office): string => $office->label())->values()->all(),
                ]),
        ]);
    }
}
