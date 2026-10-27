<?php

namespace App\Jobs;

use App\Console\RunOutput;
use App\Models\Election;
use App\Models\MaintenanceRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs a task started from the admin dashboard. Runs on its own queue, so a
 * long task does not hold up the notifications.
 */
class RunMaintenanceTask implements ShouldQueue
{
    use Queueable;

    /**
     * Importing an election or downloading the photos of a municipal one
     * (hundreds of thousands) can take more than an hour.
     */
    public int $timeout = 14_400;

    public int $tries = 1;

    public function __construct(public MaintenanceRun $run)
    {
        $this->onConnection('database-admin')->onQueue('admin');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->run->forceFill(['status' => MaintenanceRun::RUNNING, 'started_at' => now()])->save();

        $election = $this->run->election_code === null
            ? null
            : Election::query()->where('tse_code', $this->run->election_code)->first();
        [$command, $arguments] = $this->run->task->command($election);
        $output = new RunOutput($this->run);

        $exitCode = Artisan::call($command, [...$arguments, '--no-interaction' => true], $output);

        $output->save();
        $this->run->forceFill([
            'status' => $exitCode === 0 ? MaintenanceRun::DONE : MaintenanceRun::FAILED,
            'finished_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->forceFill([
            'status' => MaintenanceRun::FAILED,
            'output' => trim(($this->run->output ?? '')."\n\n".($exception?->getMessage() ?? 'A tarefa foi interrompida.')),
            'finished_at' => now(),
        ])->save();
    }
}
