<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent;

/**
 * A Markdown pipe table rendered for the console: its lines, and how many source lines the table took up so the
 * renderer continues behind it. Built by {@see ConsoleMarkdownRenderer}.
 */
class ConsoleRenderedTable
{
    /**
     * @param string[] $renderedLines The header line, the divider and one line per body row
     * @param int $consumedSourceLineCount How many source lines the table took up (header, separator, body rows)
     */
    public function __construct(
        public readonly array $renderedLines,
        public readonly int $consumedSourceLineCount,
    ) {
    }
}
