<?php

namespace App\Jobs;

use App\Models\PushFollow;
use App\Services\ResultNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Confirms to a browser that it now follows a result, with a notification
 * that also shows notifications work there.
 */
class SendFollowConfirmation implements ShouldQueue
{
    use Queueable;

    public function __construct(public PushFollow $follow) {}

    /**
     * Execute the job.
     */
    public function handle(ResultNotifier $notifier): void
    {
        $notifier->confirmFollow($this->follow);
    }
}
