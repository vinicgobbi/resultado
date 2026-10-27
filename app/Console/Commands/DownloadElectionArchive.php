<?php

namespace App\Console\Commands;

use App\Console\ConsoleSyncProgress;
use App\Models\Election;
use App\Services\Tse\ElectionCatalog;
use App\Services\Tse\RateLimitedException;
use App\Services\Tse\ResultSynchronizer;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;

#[Signature('tse:baixar-base
    {--completo : Baixa de novo também as eleições já concluídas}
    {--sem-espera : Para no primeiro 429 em vez de esperar a pausa e continuar}')]
#[Description('Descobre e baixa todas as eleições já encerradas que o TSE ainda publica, de todos os anos, sem apagar o que já está no banco')]
class DownloadElectionArchive extends Command
{
    /**
     * Execute the console command. Nothing stored is removed: elections that
     * left the TSE index stay, and a stored result is only replaced by a
     * newer one.
     */
    public function handle(ElectionCatalog $catalog, ResultSynchronizer $synchronizer): int
    {
        $discovered = $this->patiently(function () use ($catalog): bool {
            $this->components->task('Eleições publicadas pelo TSE', fn (): int => $catalog->discover());

            return true;
        });

        if ($discovered === null) {
            return self::FAILURE;
        }

        $elections = $this->elections();
        $live = Election::query()->live()->count();

        if ($live > 0) {
            $this->components->warn("{$live} eleição(ões) em apuração ficam com o tse:sincronizar.");
        }

        if ($elections->isEmpty()) {
            $this->components->info('Nenhuma eleição a baixar: a base já está completa.');

            return self::SUCCESS;
        }

        $this->components->info("{$elections->count()} eleição(ões) a baixar.");

        $progress = new ConsoleSyncProgress($this);
        $complete = 0;

        foreach ($elections as $election) {
            $progress->heading($election);
            $started = microtime(true);

            $totals = $this->patiently(fn (): array => $synchronizer->sync($election, (bool) $this->option('completo'), $progress));

            if ($totals === null) {
                return self::FAILURE;
            }

            $progress->summary($totals, microtime(true) - $started);
            $complete += (int) $totals['complete'];
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%d de %d eleição(ões) concluída(s).%s',
            $complete,
            $elections->count(),
            $complete < $elections->count() ? ' As demais continuam pelo tse:importar, de hora em hora.' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Elections already over (complete ones only with --completo), oldest
     * first, while the TSE still publishes them.
     *
     * @return Collection<int, Election>
     */
    private function elections(): Collection
    {
        return Election::query()
            ->where(fn ($query) => $query->whereNull('available_until')->orWhereDate('available_until', '>=', today()))
            ->orderBy('date')
            ->orderBy('round')
            ->get()
            ->filter(fn (Election $election): bool => match ($election->status()) {
                'pendente' => true,
                'concluida' => (bool) $this->option('completo'),
                default => false,
            })
            ->values();
    }

    /**
     * Run a step, waiting for the TSE pause and trying again after a 429
     * (it resumes from what is missing). Null when it gave up.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return TResult|null
     */
    private function patiently(Closure $step): mixed
    {
        while (true) {
            try {
                return $step();
            } catch (RateLimitedException $exception) {
                if ($this->option('sem-espera')) {
                    $this->components->warn($exception->getMessage().' Rode o comando de novo depois disso para continuar.');

                    return null;
                }

                $this->components->warn($exception->getMessage().' Aguardando para continuar...');
                Sleep::until($exception->pausedUntil->addSecond());
            }
        }
    }
}
