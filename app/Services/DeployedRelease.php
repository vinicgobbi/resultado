<?php

namespace App\Services;

/**
 * The release this process runs from, in a deploy by releases
 * (`<app>/releases/<id>` with `<app>/current` pointing to the live one). A
 * long process keeps running from its own release after a deploy, and that
 * folder is removed once a few newer ones exist: such a process has to stop
 * and let a new one start from the current release.
 */
class DeployedRelease
{
    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * Whether a newer release went live since this process started. Always
     * false outside a deploy by releases (no `current` link next to it).
     */
    public function isOutdated(): bool
    {
        $basePath = $this->basePath ?? base_path();
        $current = dirname($basePath, 2).'/current';

        if (basename(dirname($basePath)) !== 'releases' || ! is_link($current)) {
            return false;
        }

        $live = realpath($current);

        return $live !== false && $live !== realpath($basePath);
    }
}
