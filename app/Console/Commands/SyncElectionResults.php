<?php

namespace App\Console\Commands;

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

        $countryChanged = false;

        foreach ($elections as $election) {
            if (! $this->option('completo') && $election->completed_at !== null) {
                $this->components->info("{$election->name}: já concluída e sincronizada; nada a baixar.");

                continue;
            }

            $started = microtime(true);

            try {
                $totals = $synchronizer->sync($election, (bool) $this->option('completo'));
            } catch (RateLimitedException $exception) {
                $this->components->warn($exception->getMessage());

                return self::FAILURE;
            }

            $countryChanged = $countryChanged || $totals['country_changed'];

            $this->components->info(sprintf(
                '%s%s: %d resultados estaduais e %d municipais atualizados em %.1fs.',
                $election->name,
                $totals['counted_percent'] === null ? '' : ' ('.number_format($totals['counted_percent'], 2, ',', '.').'% das seções apuradas)',
                $totals['states'],
                $totals['municipalities'],
                microtime(true) - $started,
            ));
        }

        if ($countryChanged && ! $this->option('sem-notificacao') && WebPush::isConfigured()) {
            SendResultsNotification::dispatch();

            $this->components->info('Notificação push enfileirada.');
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
