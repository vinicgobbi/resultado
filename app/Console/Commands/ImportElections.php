<?php

namespace App\Console\Commands;

use App\Console\ConsoleSyncProgress;
use App\Models\Election;
use App\Models\Result;
use App\Services\Tse\RateLimitedException;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('tse:importar
    {--eleicao=* : Códigos do TSE das eleições a importar (padrão: as já encerradas e ainda incompletas)}
    {--revisar : Revisa as eleições concluídas com resultados que o TSE ainda não finalizou}')]
#[Description('Importa os resultados das eleições já encerradas enquanto o TSE ainda as publica')]
class ImportElections extends Command
{
    /**
     * Execute the console command. An import interrupted by a 429 resumes
     * on the next run from what is missing.
     */
    public function handle(ResultSynchronizer $synchronizer): int
    {
        $codes = array_map(intval(...), $this->option('eleicao'));

        if ($codes === [] && Election::query()->live()->exists()) {
            $this->components->info('Há eleição em apuração; a importação fica para depois.');

            return self::SUCCESS;
        }

        $elections = Election::query()
            ->when(
                $codes === [],
                fn (Builder $query) => $query
                    ->when(
                        $this->option('revisar'),
                        // The TSE may still retotalize them, even years later.
                        fn (Builder $query) => $query
                            ->whereNotNull('completed_at')
                            ->whereIn('tse_code', Result::query()->where('is_final', false)->select('election_code')->distinct()),
                        fn (Builder $query) => $query->whereNull('completed_at'),
                    )
                    ->whereDate('date', '<', today()->subDays(Election::LIVE_DAYS))
                    ->where(fn (Builder $query) => $query->whereNull('available_until')->orWhereDate('available_until', '>=', today())),
                fn (Builder $query) => $query->whereIn('tse_code', $codes),
            )
            ->orderBy('date')
            ->orderBy('round')
            ->get();

        if ($elections->isEmpty()) {
            $this->components->info('Nenhuma eleição para importar.');

            return self::SUCCESS;
        }

        $progress = new ConsoleSyncProgress($this);

        foreach ($elections as $election) {
            $progress->heading($election);
            $started = microtime(true);

            try {
                $totals = $synchronizer->sync($election, progress: $progress);
            } catch (RateLimitedException $exception) {
                $this->components->warn($exception->getMessage().' A importação continua na próxima execução.');

                return self::FAILURE;
            }

            $progress->summary($totals, microtime(true) - $started);
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
