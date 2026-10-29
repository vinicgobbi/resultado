<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FailedJobController extends Controller
{
    /**
     * Put every failed job back on its queue (`queue:retry all`). A task of
     * the dashboard is not run again this way: start it again from there.
     */
    public function retry(): RedirectResponse
    {
        $count = DB::table('failed_jobs')->count();

        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', $count === 1 ? '1 job voltou para a fila.' : "{$count} jobs voltaram para a fila.");
    }

    /**
     * Forget every failed job (`queue:flush`).
     */
    public function destroy(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Jobs com falha removidos.');
    }
}
