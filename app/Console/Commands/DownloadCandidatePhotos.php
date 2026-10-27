<?php

namespace App\Console\Commands;

use App\Enums\Office;
use App\Models\Candidate;
use App\Models\Election;
use App\Services\Tse\CandidatePhotos;
use App\Services\Tse\RateLimitedException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('tse:fotos
    {--eleicao=* : Códigos do TSE das eleições (padrão: as da eleição atual)}
    {--cargo=* : Cargos a baixar (padrão: todos), ex.: --cargo=governador}
    {--sem-espera : Para no primeiro 429 em vez de esperar a pausa e continuar}')]
#[Description('Baixa do TSE as fotos dos candidatos que ainda não têm foto, em ritmo que evita o bloqueio (HTTP 429)')]
class DownloadCandidatePhotos extends Command
{
    /**
     * Photos requested at a time; a 429 only loses the current batch.
     */
    private const int BATCH_SIZE = 60;

    /**
     * Execute the console command.
     */
    public function handle(CandidatePhotos $photos): int
    {
        $offices = array_map(fn (?string $value): ?Office => $value === null ? null : Office::tryFrom($value), $this->option('cargo'));

        if (in_array(null, $offices, true)) {
            $this->components->error('Cargo inválido. Use: '.collect(Office::cases())->pluck('value')->join(', ').'.');

            return self::FAILURE;
        }

        $order = array_flip(array_map(fn (Office $office): string => $office->value, Office::cases()));
        $candidates = Candidate::query()
            ->with('election')
            ->whereIn('election_code', $this->electionCodes())
            ->whereIn('office', $offices === [] ? Office::cases() : $offices)
            ->get()
            // Majority offices first: they are the ones celebrated in big.
            ->sortBy(fn (Candidate $candidate): int => $order[$candidate->office->value])
            ->reject(fn (Candidate $candidate): bool => $photos->isStored($candidate) || $photos->isMissing($candidate))
            ->values();

        if ($candidates->isEmpty()) {
            $this->components->info('Todas as fotos já foram baixadas.');

            return self::SUCCESS;
        }

        $totals = ['downloaded' => 0, 'missing' => 0, 'failed' => 0];
        $bar = $this->output->createProgressBar($candidates->count());

        foreach ($candidates->chunk(self::BATCH_SIZE) as $batch) {
            while (true) {
                try {
                    foreach ($photos->download($batch) as $key => $count) {
                        $totals[$key] += $count;
                    }

                    break;
                } catch (RateLimitedException $exception) {
                    $this->newLine(2);

                    if ($this->option('sem-espera')) {
                        $this->components->warn($exception->getMessage().' Rode o comando de novo depois disso para continuar.');

                        break 2;
                    }

                    $this->components->warn($exception->getMessage().' Aguardando para continuar...');
                    Sleep::until($exception->pausedUntil->addSecond());
                }
            }

            $bar->advance($batch->count());
        }

        $bar->finish();
        $this->newLine(2);
        $this->components->info(sprintf(
            '%d fotos baixadas, %d sem foto no TSE, %d falharam (rode de novo para tentar outra vez).',
            $totals['downloaded'],
            $totals['missing'],
            $totals['failed'],
        ));

        if (isset($exception) && $this->option('sem-espera') || $totals['failed'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Elections whose candidates are downloaded: the chosen ones, or the
     * current ones (every candidate of the past would be hundreds of
     * thousands of photos; the others are downloaded when first shown).
     *
     * @return list<int>
     */
    private function electionCodes(): array
    {
        $codes = array_values(array_map(intval(...), $this->option('eleicao')));

        if ($codes !== []) {
            return $codes;
        }

        return array_values(array_filter(Election::current()?->siblings()
            ->map(fn (Election $election): ?int => $election->firstRound()?->tse_code)
            ->all() ?? []));
    }
}
