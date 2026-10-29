<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wakes a batch of browsers up, so a large audience is sent by several
 * short jobs instead of one that could outlast its timeout.
 */
class SendPushBatch implements ShouldQueue
{
    use Queueable;

    /**
     * Browsers per job: even if every push service took the whole request
     * timeout, a batch ends well within the job timeout.
     */
    public const int SIZE = 200;

    public int $timeout = 120;

    /**
     * @param  list<int>  $subscriptionIds
     */
    public function __construct(public array $subscriptionIds) {}

    /**
     * Execute the job.
     */
    public function handle(WebPush $webPush): void
    {
        $webPush->send(PushSubscription::query()->whereKey($this->subscriptionIds)->get());
    }

    /**
     * Queue the pushes of the given browsers in batches.
     *
     * @param  iterable<int>  $subscriptionIds
     * @return int browsers queued
     */
    public static function dispatchFor(iterable $subscriptionIds): int
    {
        $queued = 0;

        foreach (collect($subscriptionIds)->unique()->values()->chunk(self::SIZE) as $batch) {
            self::dispatch(array_values($batch->all()));
            $queued += $batch->count();
        }

        return $queued;
    }
}
