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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;

#[Signature('tse:fotos
    {--eleicao=* : Códigos do TSE das eleições (padrão: as da eleição atual)}
    {--encerradas : Todas as eleições já encerradas, da mais recente para a mais antiga}
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
     * Candidates read from the database at a time: an election can have
     * hundreds of thousands.
     */
    private const int CHUNK_SIZE = 2000;

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

        if ($this->option('encerradas') && Election::query()->live()->exists()) {
            $this->components->info('Há eleição em apuração; as fotos das encerradas ficam para depois.');

            return self::SUCCESS;
        }

        $codes = $this->electionCodes();
        // Majority offices first: they are the ones celebrated in big.
        $offices = $offices === [] ? Office::cases() : $offices;
        $total = Candidate::query()->whereIn('election_code', $codes)->whereIn('office', $offices)->count();

        if ($total === 0) {
            $this->components->info('Nenhum candidato nessas eleições.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $stopped = false;
        $totals = ['downloaded' => 0, 'missing' => 0, 'failed' => 0];

        foreach ($offices as $office) {
            foreach ($codes as $code) {
                Candidate::query()
                    ->with('election')
                    ->where('election_code', $code)
                    ->where('office', $office)
                    ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($photos, $bar, &$stopped, &$totals): bool {
                        $pending = $chunk
                            ->reject(fn (Candidate $candidate): bool => $photos->isStored($candidate) || $photos->isMissing($candidate))
                            ->values();

                        foreach ($pending->chunk(self::BATCH_SIZE) as $batch) {
                            if (! $this->downloadBatch($photos, $batch, $totals)) {
                                $stopped = true;

                                return false;
                            }
                        }

                        $bar->advance($chunk->count());

                        return true;
                    });

                if ($stopped) {
                    break 2;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        if (! $stopped && array_sum($totals) === 0) {
            $this->components->info('Todas as fotos já foram baixadas.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d fotos baixadas, %d sem foto no TSE, %d falharam (rode de novo para tentar outra vez).',
            $totals['downloaded'],
            $totals['missing'],
            $totals['failed'],
        ));

        return $stopped && $this->option('sem-espera') || $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Download a batch, waiting out a 429 unless asked not to. False when
     * the command has to stop.
     *
     * @param  Collection<int, Candidate>  $batch
     * @param  array{downloaded: int, missing: int, failed: int}  $totals
     */
    private function downloadBatch(CandidatePhotos $photos, Collection $batch, array &$totals): bool
    {
        while (true) {
            // The counting of a new election needs the TSE more than the past.
            if ($this->option('encerradas') && Election::query()->live()->exists()) {
                $this->newLine(2);
                $this->components->info('Uma eleição entrou em apuração; as fotos continuam depois.');

                return false;
            }

            try {
                foreach ($photos->download($batch) as $key => $count) {
                    $totals[$key] += $count;
                }

                return true;
            } catch (RateLimitedException $exception) {
                $this->newLine(2);

                if ($this->option('sem-espera')) {
                    $this->components->warn($exception->getMessage().' Rode o comando de novo depois disso para continuar.');

                    return false;
                }

                $this->components->warn($exception->getMessage().' Aguardando para continuar...');
                Sleep::until($exception->pausedUntil->addSecond());
            }
        }
    }

    /**
     * Elections whose candidates are downloaded, by the first round (where
     * the candidates are registered): the chosen ones, every one already
     * over, or the current ones (the others are downloaded when first shown).
     *
     * @return list<int>
     */
    private function electionCodes(): array
    {
        $codes = array_values(array_map(intval(...), $this->option('eleicao')));

        $elections = match (true) {
            $codes !== [] => Election::query()->whereIn('tse_code', $codes)->get(),
            (bool) $this->option('encerradas') => Election::query()
                ->whereDate('date', '<', today()->subDays(Election::LIVE_DAYS))
                ->orderByDesc('date')
                ->get(),
            default => Election::current()?->siblings() ?? collect(),
        };

        return array_values(array_unique(array_filter(
            $elections->map(fn (Election $election): ?int => $election->firstRound()?->tse_code)->all(),
        )));
    }
}
