<?php

namespace App\Console\Commands;

use App\Console\ConsoleSyncProgress;
use App\Jobs\SendResultsNotification;
use App\Models\Election;
use App\Services\Tse\RateLimitedException;
use App\Services\Tse\ResultSynchronizer;
use App\Services\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('tse:sincronizar
    {--eleicao=* : Códigos do TSE das eleições a sincronizar (padrão: as que estão em apuração)}
    {--completo : Rebaixa tudo, mesmo eleições concluídas e municípios que não mudaram}
    {--sem-notificacao : Não envia notificações push}')]
#[Description('Sincroniza os resultados das eleições em apuração a partir da divulgação do TSE')]
class SyncElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResultSynchronizer $synchronizer): int
    {
        $elections = $this->elections();

        if ($elections->isEmpty()) {
            $this->components->info('Nenhuma eleição em apuração agora.');

            return self::SUCCESS;
        }

        $events = [];
        $progress = new ConsoleSyncProgress($this);

        foreach ($elections as $election) {
            $progress->heading($election);

            if (! $this->option('completo') && $election->completed_at !== null) {
                $this->components->info('Já concluída e sincronizada; nada a baixar.');

                continue;
            }

            $started = microtime(true);

            try {
                $totals = $synchronizer->sync($election, (bool) $this->option('completo'), $progress);
            } catch (RateLimitedException $exception) {
                $this->components->warn($exception->getMessage());

                return self::FAILURE;
            }

            $events = [...$events, ...$totals['events']];
            $progress->summary($totals, microtime(true) - $started);
        }

        $this->newLine();

        if ($events !== [] && ! $this->option('sem-notificacao') && WebPush::isConfigured()) {
            SendResultsNotification::dispatch($events);

            $this->components->info('Notificações push enfileiradas.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Election>
     */
    private function elections(): Collection
    {
        $codes = array_map(intval(...), $this->option('eleicao'));

        return Election::query()
            ->when(
                $codes === [],
                // A full sync also downloads again the completed ones.
                fn ($query) => $this->option('completo') ? $query->recent() : $query->live(),
                fn ($query) => $query->whereIn('tse_code', $codes),
            )
            ->orderBy('date')
            ->get();
    }
}
