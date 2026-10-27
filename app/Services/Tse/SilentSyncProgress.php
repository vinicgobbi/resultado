<?php

namespace App\Services\Tse;

use Closure;

/**
 * Progress of a synchronization nobody is watching.
 */
final class SilentSyncProgress implements SyncProgress
{
    public function step(string $description, Closure $step): mixed
    {
        return $step();
    }

    public function start(string $description, int $total): void {}

    public function advance(int $count): void {}

    public function finish(): void {}
}
