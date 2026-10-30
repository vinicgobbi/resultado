<?php

namespace App\Http\Controllers;

use App\Models\Election;
use Inertia\Inertia;
use Inertia\Response;

class ElectionController extends Controller
{
    /**
     * Every election, the most recent first, with its rounds.
     */
    public function index(): Response
    {
        return Inertia::render('elections/index', [
            'cycles' => Election::cycles(),
        ]);
    }
}
