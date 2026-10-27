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
}
