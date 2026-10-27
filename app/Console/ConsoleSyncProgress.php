<?php

namespace App\Console;

use App\Models\Election;
use App\Services\Tse\SyncProgress;
use Closure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Shows the progress of a synchronization in the terminal: each step with
 * its time and status, and a progress bar for the municipality files.
 */
final class ConsoleSyncProgress implements SyncProgress
{
    private ?ProgressBar $bar = null;

    public function __construct(private readonly Command $command) {}

    /**
     * Which election is about to be synchronized.
     */
    public function heading(Election $election): void
    {
        $this->command->newLine();
        $this->command->line(sprintf(
            '  <options=bold>%s</> <fg=gray>· código %d · %s</>',
            $election->name,
            $election->tse_code,
            $election->date->format('d/m/Y'),
        ));
        $this->command->newLine();
    }

    /**
     * What the synchronization of an election did.
     *
     * @param  array{states: int, municipalities: int, counted_percent: float|null, complete: bool, failures: int}  $totals
     */
    public function summary(array $totals, float $seconds): void
    {
        $components = $this->command->outputComponents();

        $components->twoColumnDetail('Resultados estaduais baixados', (string) $totals['states']);
        $components->twoColumnDetail('Resultados municipais baixados', number_format($totals['municipalities'], 0, ',', '.'));

        if ($totals['counted_percent'] !== null) {
            $components->twoColumnDetail('Seções apuradas no país', number_format($totals['counted_percent'], 2, ',', '.').'%');
        }

        if ($totals['failures'] > 0) {
            $components->twoColumnDetail('Arquivos que falharam', "<fg=red>{$totals['failures']}</> (tentados de novo na próxima vez)");
        }

        $components->twoColumnDetail('Situação', $totals['complete'] ? '<fg=green>concluída</>' : '<fg=yellow>em andamento</>');
        $components->twoColumnDetail('Tempo', sprintf('%.1fs', $seconds));
    }

    public function step(string $description, Closure $step): mixed
    {
        $result = null;

        $this->command->outputComponents()->task($description, function () use ($step, &$result): bool {
            $result = $step();

            return true;
        });

        return $result;
    }

    public function start(string $description, int $total): void
    {
        $this->bar = $this->command->getOutput()->createProgressBar($total);
        $this->bar->setFormat("  %message%\n  %current%/%max% [%bar%] %percent:3s%% · %elapsed:6s%");
        $this->bar->setMessage($description);
        $this->bar->start();
    }

    public function advance(int $count): void
    {
        $this->bar?->advance($count);
    }

    public function finish(): void
    {
        $this->bar?->finish();
        $this->bar = null;
        $this->command->newLine(2);
    }
}
