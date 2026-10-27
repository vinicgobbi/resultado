<?php

namespace App\Jobs;

use App\Services\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notifies every subscribed device that the national result changed. Runs on
 * the queue so a large audience does not hold up the next synchronization.
 */
class SendResultsNotification implements ShouldQueue
{
    use Queueable;

    /**
     * A notification older than this is no longer news.
     */
    public int $timeout = 120;

    /**
     * Execute the job.
     */
    public function handle(WebPush $webPush): void
    {
        $webPush->broadcast();
    }
}
