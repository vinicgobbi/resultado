<?php

namespace App\Services\Tse;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The TSE answered "429 Too Many Requests": every request to it is paused
 * until the given time, so the block is not extended by insisting.
 */
class RateLimitedException extends RuntimeException
{
    public function __construct(public readonly Carbon $pausedUntil)
    {
        parent::__construct("O TSE limitou as requisições (HTTP 429). Nova tentativa a partir de {$pausedUntil->timezone('America/Sao_Paulo')->format('H:i:s')}.");
    }
}
