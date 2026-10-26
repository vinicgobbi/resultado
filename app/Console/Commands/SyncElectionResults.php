<?php

namespace App\Console\Commands;

use App\Enums\Office;
use App\Services\Tse\RateLimitedException;
use App\Services\Tse\ResultSynchronizer;
use App\Services\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tse:sincronizar
    {--turno= : Turno a sincronizar (padrão: turno ativo)}
    {--completo : Rebaixa tudo, mesmo eleições concluídas e municípios que não mudaram}
    {--sem-notificacao : Não envia notificações push}')]
#[Description('Sincroniza os resultados de todos os cargos a partir da divulgação do TSE')]
class SyncElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResultSynchronizer $synchronizer, WebPush $webPush): int
    {
        $round = (int) ($this->option('turno') ?: config('eleicoes.turno_ativo'));

        // Offices grouped by the TSE election that holds them.
        $elections = collect(Office::forRound($round))
            ->groupBy(fn (Office $office): int => (int) $office->electionCode($round))
            ->reject(fn ($offices, int $electionCode): bool => $electionCode === 0);

        if ($elections->isEmpty()) {
            $this->components->error("Turno {$round} não configurado.");

            return self::FAILURE;
        }

        $countryChanged = false;

        foreach ($elections as $electionCode => $offices) {
            if (! $this->option('completo') && ResultSynchronizer::isComplete($electionCode)) {
                $this->components->info("Eleição {$electionCode} já concluída e sincronizada; nada a baixar.");

                continue;
            }

            $started = microtime(true);

            try {
                $totals = $synchronizer->sync($electionCode, $offices->all(), (bool) $this->option('completo'));
            } catch (RateLimitedException $exception) {
                $this->components->warn($exception->getMessage());

                return self::FAILURE;
            }

            $countryChanged = $countryChanged || $totals['country_changed'];

            $this->components->info(sprintf(
                '%dº turno, %s%s: %d resultados estaduais e %d municipais atualizados em %.1fs.',
                $round,
                $offices->map(fn (Office $office): string => $office->label())->join(', ', ' e '),
                $totals['counted_percent'] === null ? '' : ' ('.number_format($totals['counted_percent'], 2, ',', '.').'% das seções apuradas)',
                $totals['states'],
                $totals['municipalities'],
                microtime(true) - $started,
            ));
        }

        if ($countryChanged && ! $this->option('sem-notificacao')) {
            $delivered = $webPush->broadcast();

            if ($delivered > 0) {
                $this->components->info("Notificação enviada para {$delivered} dispositivo(s).");
            }
        }

        return self::SUCCESS;
    }
}
