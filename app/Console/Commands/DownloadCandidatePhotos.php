<?php

namespace App\Console\Commands;

use App\Enums\Office;
use App\Models\Candidate;
use App\Models\Election;
use App\Services\Tse\CandidatePhotos;
use App\Services\Tse\RateLimitedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

#[Signature('tse:fotos
    {--eleicao=* : Códigos do TSE das eleições (padrão: as da eleição atual)}
    {--encerradas : Todas as eleições já encerradas, da mais recente para a mais antiga}
    {--cargo=* : Cargos a baixar (padrão: todos), ex.: --cargo=governador}
    {--sem-espera : Para no primeiro 429 em vez de esperar a pausa e continuar}
    {--minutos= : Para depois de tantos minutos; a próxima execução continua de onde parou}
    {--do-inicio : Ignora onde a última execução parou e verifica tudo de novo}')]
#[Description('Baixa do TSE as fotos dos candidatos que ainda não têm foto, em ritmo que evita o bloqueio (HTTP 429)')]
class DownloadCandidatePhotos extends Command
{
    /**
     * Exit code when it stopped at the time limit with photos still to check.
     */
    public const int INCOMPLETE = 3;

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
     * Without a terminal (the dashboard, the scheduler) the progress is
     * printed as a new line this often.
     */
    private const int PROGRESS_EVERY_SECONDS = 15;

    /**
     * While waiting out a 429, a line is printed this often (and a run
     * cancelled from the dashboard stops).
     */
    private const int WAIT_STEP_SECONDS = 60;

    private const string DONE = 'concluido';

    private const string STOPPED = 'parado';

    private const string OUT_OF_TIME = 'sem-tempo';

    private ?CarbonImmutable $deadline = null;

    /**
     * Execute the console command. Where it stopped is kept, so the next run
     * with the same options continues from there instead of checking every
     * candidate again.
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
        $counts = Candidate::query()
            ->whereIn('election_code', $codes)
            ->whereIn('office', $offices)
            ->groupBy('office', 'election_code')
            ->selectRaw('office, election_code, count(*) as total')
            ->get()
            ->mapWithKeys(fn (Candidate $candidate): array => ["{$candidate->office->value}-{$candidate->election_code}" => (int) $candidate->getAttribute('total')]);
        $total = $counts->sum();

        if ($total === 0) {
            $this->components->info('Nenhum candidato nessas eleições.');

            return self::SUCCESS;
        }

        /** @var list<array{0: Office, 1: int}> $parts */
        $parts = collect($offices)
            ->flatMap(fn (Office $office): array => array_map(fn (int $code): array => [$office, $code], $codes))
            ->filter(fn (array $part): bool => $counts->has("{$part[0]->value}-{$part[1]}"))
            ->values()
            ->all();
        $cursorKey = 'tse:fotos.cursor.'.md5(json_encode([$codes, array_map(fn (Office $office): string => $office->value, $offices)], JSON_THROW_ON_ERROR));

        if ($this->option('do-inicio')) {
            Cache::forget($cursorKey);
        }

        [$start, $cursor, $checked] = $this->resume($parts, Cache::get($cursorKey), $counts);

        if ($cursor !== null) {
            $this->components->info(sprintf(
                'Continuando de onde a última execução parou: %s de %s candidatos já verificados (use --do-inicio para verificar tudo de novo).',
                number_format($checked, 0, ',', '.'),
                number_format($total, 0, ',', '.'),
            ));
        }

        $minutes = (int) $this->option('minutos');
        $this->deadline = $minutes > 0 ? now()->toImmutable()->addMinutes($minutes) : null;
        $names = Election::query()->whereIn('tse_code', $codes)->pluck('name', 'tse_code');
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% · %message%');

        if (! $this->output->isDecorated()) {
            $bar->minSecondsBetweenRedraws(self::PROGRESS_EVERY_SECONDS);
            $bar->maxSecondsBetweenRedraws(self::PROGRESS_EVERY_SECONDS);
        }

        $outcome = self::DONE;
        $totals = ['downloaded' => 0, 'missing' => 0, 'failed' => 0];
        $bar->setMessage('');
        $bar->start(startAt: $checked);

        foreach ($parts as $index => [$office, $code]) {
            if ($index < $start) {
                continue;
            }

            $label = "{$office->label()} · ".($names[$code] ?? $code);
            $bar->setMessage("{$label} · {$totals['downloaded']} baixadas");

            Candidate::query()
                ->with('election')
                ->where('election_code', $code)
                ->where('office', $office)
                ->when($index === $start && $cursor !== null, fn (Builder $query) => $query->where('id', '>', $cursor['id'] ?? 0))
                ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($photos, $bar, $office, $code, $label, $cursorKey, &$outcome, &$totals): bool {
                    $pending = $photos->pending($chunk);
                    $bar->advance($chunk->count() - $pending->count());

                    foreach ($pending->chunk(self::BATCH_SIZE) as $batch) {
                        $outcome = $this->downloadBatch($photos, $batch, $totals);

                        if ($outcome !== self::DONE) {
                            return false;
                        }

                        Cache::forever($cursorKey, ['office' => $office->value, 'election' => $code, 'id' => $batch->last()->id]);
                        $bar->setMessage("{$label} · {$totals['downloaded']} baixadas");
                        $bar->advance($batch->count());
                    }

                    Cache::forever($cursorKey, ['office' => $office->value, 'election' => $code, 'id' => $chunk->last()->id]);

                    return true;
                });

            if ($outcome !== self::DONE) {
                break;
            }
        }

        if ($outcome === self::DONE) {
            Cache::forget($cursorKey);
            $bar->finish();
        }

        $this->newLine(2);

        if ($outcome === self::DONE && array_sum($totals) === 0) {
            $this->components->info('Todas as fotos já foram baixadas.');

            return self::SUCCESS;
        }

        if ($outcome === self::OUT_OF_TIME) {
            $this->components->info("Parou depois de {$minutes} minutos, para não passar do tempo limite. A próxima execução continua de onde parou.");
        }

        $this->components->info(sprintf(
            '%d fotos baixadas, %d sem foto no TSE, %d falharam (rode de novo para tentar outra vez).',
            $totals['downloaded'],
            $totals['missing'],
            $totals['failed'],
        ));

        return match (true) {
            $outcome === self::OUT_OF_TIME => self::INCOMPLETE,
            $outcome === self::STOPPED && $this->option('sem-espera'), $totals['failed'] > 0 => self::FAILURE,
            default => self::SUCCESS,
        };
    }

    /**
     * Where the last run stopped: the part to start from, the cursor in it
     * and how many candidates were already checked. From the start when
     * nothing was kept or the elections changed since.
     *
     * @param  list<array{0: Office, 1: int}>  $parts
     * @param  SupportCollection<string, int>  $counts
     * @return array{0: int, 1: array{office: string, election: int, id: int}|null, 2: int}
     */
    private function resume(array $parts, mixed $cursor, SupportCollection $counts): array
    {
        if (! is_array($cursor) || ! isset($cursor['office'], $cursor['election'], $cursor['id'])) {
            return [0, null, 0];
        }

        foreach ($parts as $index => [$office, $code]) {
            if ($office->value !== $cursor['office'] || $code !== $cursor['election']) {
                continue;
            }

            $before = array_sum(array_map(fn (array $part): int => $counts["{$part[0]->value}-{$part[1]}"] ?? 0, array_slice($parts, 0, $index)));
            $inPart = Candidate::query()->where('election_code', $code)->where('office', $office)->where('id', '<=', $cursor['id'])->count();

            return [$index, $cursor, $before + $inPart];
        }

        return [0, null, 0];
    }

    /**
     * Download a batch, waiting out a 429 unless asked not to.
     *
     * @param  SupportCollection<int, Candidate>  $batch
     * @param  array{downloaded: int, missing: int, failed: int}  $totals
     * @return self::DONE|self::STOPPED|self::OUT_OF_TIME
     */
    private function downloadBatch(CandidatePhotos $photos, SupportCollection $batch, array &$totals): string
    {
        while (true) {
            if ($this->deadline?->isPast()) {
                return self::OUT_OF_TIME;
            }

            // The counting of a new election needs the TSE more than the past.
            if ($this->option('encerradas') && Election::query()->live()->exists()) {
                $this->newLine(2);
                $this->components->info('Uma eleição entrou em apuração; as fotos continuam depois, de onde pararam.');

                return self::STOPPED;
            }

            try {
                foreach ($photos->download($batch) as $key => $count) {
                    $totals[$key] += $count;
                }

                return self::DONE;
            } catch (RateLimitedException $exception) {
                $this->newLine(2);

                if ($this->option('sem-espera')) {
                    $this->components->warn($exception->getMessage().' Rode o comando de novo depois disso para continuar de onde parou.');

                    return self::STOPPED;
                }

                if ($this->deadline !== null && $exception->pausedUntil->greaterThanOrEqualTo($this->deadline)) {
                    $this->components->warn($exception->getMessage());

                    return self::OUT_OF_TIME;
                }

                $this->components->warn($exception->getMessage().' Aguardando para continuar...');
                $this->waitUntil($exception->pausedUntil->addSecond());
            }
        }
    }

    /**
     * Sleep until the given moment, printing a line now and then: a long
     * silence looks stuck and keeps a cancelled run from stopping.
     */
    private function waitUntil(CarbonInterface $until): void
    {
        $remaining = (int) ceil(now()->diffInSeconds($until));

        while ($remaining > 0) {
            Sleep::for(min(self::WAIT_STEP_SECONDS, $remaining))->seconds();
            $remaining = (int) ceil(now()->diffInSeconds($until));

            if ($remaining > 0) {
                $this->line(' Aguardando o TSE até '.$until->format('H:i').'...');
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
