<?php

namespace App\Console\Commands;

use App\Models\Election;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('resultados:limpar-cache')]
#[Description('Faz as páginas de resultados de todas as eleições serem calculadas de novo na próxima visita')]
class ClearResultsCache extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $codes = Election::query()->pluck('tse_code');

        $codes->each(fn (int $code) => ResultSynchronizer::invalidate($code));

        $this->components->info("Cache de {$codes->count()} eleições limpo.");

        return self::SUCCESS;
    }
}
