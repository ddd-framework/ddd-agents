<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Where the cursor of the {@see ConsoleLineEditor} stands in a multi-line buffer: the line (the count of `\n` before
 * the cursor) and the column within that line, both counted from 0, without the terminal's wrapping.
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class ConsoleLineEditorCursorPosition
{
    /**
     * @param int $lineIndex the line of the cursor, from 0
     * @param int $columnIndex the characters between the start of that line and the cursor
     */
    public function __construct(
        public readonly int $lineIndex,
        public readonly int $columnIndex,
    ) {
    }
}
