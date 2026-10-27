<?php

namespace App\Http\Controllers;

use App\Models\Election;
use Inertia\Inertia;
use Inertia\Response;

class ElectionController extends Controller
{
    /**
     * Every election day, the most recent first.
     */
    public function index(): Response
    {
        return Inertia::render('elections/index', [
            'days' => Election::days(),
        ]);
    }
}
