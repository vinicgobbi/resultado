<?php

namespace App\Console\Commands;

use App\Services\Tse\ResultSynchronizer;
use App\Services\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tse:sincronizar
    {--turno= : Turno a sincronizar (padrão: turno ativo)}
    {--completo : Rebaixa todos os municípios, mesmo os que não mudaram}
    {--sem-notificacao : Não envia notificações push}')]
#[Description('Sincroniza os resultados de presidente a partir da divulgação do TSE')]
class SyncElectionResults extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResultSynchronizer $synchronizer, WebPush $webPush): int
    {
        $round = (int) ($this->option('turno') ?: config('eleicoes.turno_ativo'));
        $electionCode = config("eleicoes.turnos.{$round}");

        if ($electionCode === null) {
            $this->components->error("Turno {$round} não configurado.");

            return self::FAILURE;
        }

        $started = microtime(true);
        $totals = $synchronizer->sync((int) $electionCode, (bool) $this->option('completo'));

        $this->components->info(sprintf(
            '%dº turno: %s%% das seções apuradas. %d estados e %d municípios atualizados em %.1fs.',
            $round,
            number_format($totals['counted_percent'], 2, ',', '.'),
            $totals['states'],
            $totals['municipalities'],
            microtime(true) - $started,
        ));

        if ($totals['country_changed'] && ! $this->option('sem-notificacao')) {
            $delivered = $webPush->broadcast();

            if ($delivered > 0) {
                $this->components->info("Notificação enviada para {$delivered} dispositivo(s).");
            }
        }

        return self::SUCCESS;
    }
}
