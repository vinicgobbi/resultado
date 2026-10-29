<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunMaintenanceTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FailedJobController extends Controller
{
    /**
     * Put the failed jobs back on their queues (`queue:retry`). A task of the
     * dashboard would end right away (its run is already marked as failed),
     * so those are left out: they are started again from the dashboard.
     */
    public function retry(): RedirectResponse
    {
        $failed = DB::table('failed_jobs')->get(['uuid', 'queue']);
        $retryable = $failed->where('queue', '!==', RunMaintenanceTask::QUEUE)->pluck('uuid')->all();
        $tasks = $failed->count() - count($retryable);

        if ($retryable !== []) {
            Artisan::call('queue:retry', ['id' => $retryable]);
        }

        $message = match (count($retryable)) {
            0 => 'Nenhum job voltou para a fila.',
            1 => '1 job voltou para a fila.',
            default => count($retryable).' jobs voltaram para a fila.',
        };

        if ($tasks > 0) {
            $message .= $tasks === 1
                ? ' 1 tarefa do painel ficou de fora: inicie-a de novo em "Tarefas".'
                : " {$tasks} tarefas do painel ficaram de fora: inicie-as de novo em \"Tarefas\".";
        }

        return back()->with('success', $message);
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
