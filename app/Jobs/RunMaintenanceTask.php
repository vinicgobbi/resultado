<?php

namespace App\Jobs;

use App\Console\Commands\DownloadCandidatePhotos;
use App\Console\RunOutput;
use App\Exceptions\MaintenanceRunCancelled;
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

    /**
     * A task that can continue later stops by itself this long, leaving
     * margin before the timeout kills it.
     */
    public const int TIME_LIMIT_MINUTES = 230;

    public int $tries = 1;

    public function __construct(public MaintenanceRun $run)
    {
        $this->onConnection('database-admin')->onQueue('admin');
    }

    /**
     * Execute the job. A run cancelled while queued does not start; one
     * cancelled while running stops at the next line its command prints.
     */
    public function handle(): void
    {
        if ($this->run->status !== MaintenanceRun::QUEUED) {
            return;
        }

        $this->run->forceFill(['status' => MaintenanceRun::RUNNING, 'started_at' => now()])->save();

        $election = $this->run->election_code === null
            ? null
            : Election::query()->where('tse_code', $this->run->election_code)->first();
        [$command, $arguments] = $this->run->task->command($election);
        $output = new RunOutput($this->run);

        try {
            $exitCode = Artisan::call($command, [...$arguments, '--no-interaction' => true], $output);
        } catch (MaintenanceRunCancelled) {
            $output->writeln("\nTarefa cancelada pelo painel.");
            $exitCode = null;
        }

        $continues = $exitCode === DownloadCandidatePhotos::INCOMPLETE && $this->run->task->continuesUntilDone();

        if ($continues) {
            $output->writeln('Uma nova execução continua de onde esta parou.');
        }

        $output->save();

        // The status of a cancelled run was already set by the dashboard.
        $finished = MaintenanceRun::query()
            ->whereKey($this->run->id)
            ->where('status', MaintenanceRun::RUNNING)
            ->update([
                'status' => $exitCode === 0 || $continues ? MaintenanceRun::DONE : MaintenanceRun::FAILED,
                'finished_at' => now(),
            ]);

        if ($continues && $finished === 1) {
            self::dispatch(MaintenanceRun::create([
                'user_id' => $this->run->user_id,
                'task' => $this->run->task,
                'election_code' => $this->run->election_code,
                'status' => MaintenanceRun::QUEUED,
            ]));
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->run->wasCancelled()) {
            return;
        }

        $this->run->forceFill([
            'status' => MaintenanceRun::FAILED,
            'output' => trim(($this->run->output ?? '')."\n\n".($exception?->getMessage() ?? 'A tarefa foi interrompida.')),
            'finished_at' => now(),
        ])->save();
    }
}
