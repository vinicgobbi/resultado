<?php

namespace App\Jobs;

use App\Services\ResultNotifier;
use App\Services\Tse\SyncEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notifies the followers of the results that changed in a sync. Runs on the
 * queue so a large audience does not hold up the next synchronization.
 */
class SendResultsNotification implements ShouldQueue
{
    use Queueable;

    /**
     * A notification older than this is no longer news.
     */
    public int $timeout = 120;

    /**
     * @param  list<SyncEvent>  $events
     */
    public function __construct(public array $events) {}

    /**
     * Execute the job.
     */
    public function handle(ResultNotifier $notifier): void
    {
        $notifier->notify($this->events);
    }
}
