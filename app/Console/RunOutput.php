<?php

namespace App\Console;

use App\Models\MaintenanceRun;
use Symfony\Component\Console\Output\Output;

/**
 * Console output of a maintenance run, saved on the run as it is written so
 * the dashboard shows the progress of a long task.
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

    private float $savedAt = 0;

    public function __construct(private readonly MaintenanceRun $run)
    {
        parent::__construct(decorated: false);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message.($newline ? "\n" : '');

        if (strlen($this->buffer) > self::MAX_LENGTH) {
            $this->buffer = substr($this->buffer, -self::MAX_LENGTH);
        }

        if (microtime(true) - $this->savedAt >= self::SAVE_EVERY_SECONDS) {
            $this->save();
        }
    }

    public function save(): void
    {
        $this->savedAt = microtime(true);
        $this->run->forceFill(['output' => $this->buffer])->save();
    }
}
