<?php

namespace App\Services\Tse;

use Closure;

/**
 * Receives what a synchronization is doing, so a command run by hand can
 * show it.
 */
interface SyncProgress
{
    /**
     * Run one step of the synchronization.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return TResult
     */
    public function step(string $description, Closure $step): mixed;

    /**
     * Downloads of many files: started with their total, advanced batch by
     * batch and then finished.
     */
    public function start(string $description, int $total): void;

    public function advance(int $count): void;

    public function finish(): void;
}
