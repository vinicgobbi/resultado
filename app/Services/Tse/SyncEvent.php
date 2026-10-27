<?php

namespace App\Services\Tse;

use App\Enums\NotificationCategory;
use App\Enums\Office;

/**
 * Something that happened to a followed result during a sync.
 */
final readonly class SyncEvent
{
    public function __construct(
        public NotificationCategory $category,
        public int $electionCode,
        public Office $office,
        public string $scopeCode,
    ) {}
}
