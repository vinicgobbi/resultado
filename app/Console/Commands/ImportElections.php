<?php

namespace App\Console\Commands;

use App\Models\Election;
use App\Services\Tse\RateLimitedException;
use App\Services\Tse\ResultSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('tse:importar
    {--eleicao=* : Códigos do TSE das eleições a importar (padrão: as já encerradas e ainda incompletas)}')]
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
                    ->whereNull('completed_at')
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

        foreach ($elections as $election) {
            $started = microtime(true);

            try {
                $totals = $synchronizer->sync($election);
            } catch (RateLimitedException $exception) {
                $this->components->warn($exception->getMessage().' A importação continua na próxima execução.');

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                '%s: %d resultados estaduais e %d municipais importados em %.1fs%s.',
                $election->name,
                $totals['states'],
                $totals['municipalities'],
                microtime(true) - $started,
                $totals['complete'] ? '; concluída' : '',
            ));
        }

        return self::SUCCESS;
    }
}
