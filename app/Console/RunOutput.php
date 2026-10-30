<?php

namespace App\Console;

use App\Exceptions\MaintenanceRunCancelled;
use App\Models\MaintenanceRun;
use Carbon\CarbonImmutable;
use Symfony\Component\Console\Output\Output;

/**
 * Console output of a maintenance run, saved on the run as it is written so
 * the dashboard shows the progress of a long task. A run cancelled from the
 * dashboard stops the next time its command prints something.
 */
class RunOutput extends Output
{
    /**
     * Only the end of a long output is kept.
     */
    private const int MAX_LENGTH = 60_000;

    /**
     * Saved at most this often while the command writes.
     */
    private const float SAVE_EVERY_SECONDS = 1.0;

    private string $buffer = '';

    private ?CarbonImmutable $savedAt = null;

    private bool $cancelled = false;

    public function __construct(private readonly MaintenanceRun $run)
    {
        parent::__construct(decorated: false);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message.($newline ? "\n" : '');

        if (strlen($this->buffer) > self::MAX_LENGTH) {
            $this->buffer = mb_strcut($this->buffer, strlen($this->buffer) - self::MAX_LENGTH, encoding: 'UTF-8');
        }

        if ($this->cancelled || ($this->savedAt !== null && $this->savedAt->diffInSeconds(now()) < self::SAVE_EVERY_SECONDS)) {
            return;
        }

        $this->save();

        if ($this->run->wasCancelled()) {
            $this->cancelled = true;

            throw new MaintenanceRunCancelled;
        }
    }

    public function save(): void
    {
        $this->savedAt = now()->toImmutable();
        $this->run->forceFill(['output' => $this->buffer])->save();
    }
}
