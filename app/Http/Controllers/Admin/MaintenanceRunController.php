<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MaintenanceTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMaintenanceRunRequest;
use App\Jobs\RunMaintenanceTask;
use App\Models\MaintenanceRun;
use Illuminate\Http\RedirectResponse;

class MaintenanceRunController extends Controller
{
    /**
     * Queue a task; the dashboard follows its progress.
     */
    public function store(StoreMaintenanceRunRequest $request): RedirectResponse
    {
        $task = $request->enum('task', MaintenanceTask::class) ?? MaintenanceTask::Sync;

        $run = MaintenanceRun::create([
            'user_id' => $request->user()?->id,
            'task' => $task,
            'election_code' => $task->needsElection() ? $request->integer('election') : null,
            'status' => MaintenanceRun::QUEUED,
        ]);

        RunMaintenanceTask::dispatch($run);

        return back()->with('success', "\"{$task->label()}\" está na fila e começa em até 15 segundos.");
    }

    /**
     * Cancel a queued or running task. A running command stops at the next
     * line it prints; a run whose worker died is closed right away.
     */
    public function cancel(MaintenanceRun $maintenanceRun): RedirectResponse
    {
        $cancelled = MaintenanceRun::query()
            ->whereKey($maintenanceRun->id)
            ->unfinished()
            ->update(['status' => MaintenanceRun::CANCELLED, 'finished_at' => now()]);

        if ($cancelled === 0) {
            return back()->with('error', "\"{$maintenanceRun->task->label()}\" já terminou.");
        }

        return back()->with('success', "\"{$maintenanceRun->task->label()}\" foi cancelada.");
    }
}
