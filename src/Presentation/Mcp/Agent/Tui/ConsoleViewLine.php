<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent\Tui;

/**
 * One line of the transcript as it is currently shown, with the id of the block it belongs to — so a row on screen
 * can be mapped back to its {@see ConsoleViewBlock}. Built by {@see ConsoleViewModel::visibleLines()}.
 */
class ConsoleViewLine
{
    /**
     * @param string $blockId The id of the block the line belongs to
     * @param string $text The text of the line
     */
    public function __construct(
        public readonly string $blockId,
        public readonly string $text,
    ) {
    }
}
