<?php

namespace App\Jobs;

use App\Enums\Office;
use App\Services\ResultNotifier;
use App\Services\ResultPlace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a notice written in the admin dashboard to the followers of a
 * result, or to everyone without an office.
 */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $title,
        public string $body,
        public ?Office $office = null,
        public ?string $scopeCode = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ResultNotifier $notifier): void
    {
        $place = $this->office === null || $this->scopeCode === null ? null : new ResultPlace($this->office, $this->scopeCode);

        $notifier->announce($this->title, $this->body, $place);
    }
}
