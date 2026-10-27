<?php

namespace App\Console\Commands;

use App\Services\Tse\ElectionCatalog;
use App\Services\Tse\RateLimitedException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tse:descobrir')]
#[Description('Cadastra as eleições publicadas no índice do TSE (somente as ordinárias)')]
class DiscoverElections extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ElectionCatalog $catalog): int
    {
        try {
            $count = $catalog->discover();
        } catch (RateLimitedException $exception) {
            $this->components->warn($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$count} eleições cadastradas ou atualizadas.");

        return self::SUCCESS;
    }
}
