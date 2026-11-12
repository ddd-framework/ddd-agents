<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Interfaces;

/**
 * Takes the progress lines of a long-running conversation job — the catalogue run, the demand summary, the background
 * rewrites of the message table — one line per package, area or window, for the console command that started it.
 * The command decides how a line is printed.
 */
interface AIConversationProgressReporterInterface
{
    /** Report one progress line. */
    public function reportProgress(string $progressLine): void;
}
