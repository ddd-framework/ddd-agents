<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The input of the {@see ConsoleLineEditor} after one editing action: the characters of the buffer, the cursor and
 * what the read loop does next ({@see ConsoleLineEditor::STATUS_EDITING}, `STATUS_SUBMIT`, `STATUS_CANCEL`,
 * `STATUS_HISTORY_PREV`, `STATUS_HISTORY_NEXT`).
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class ConsoleLineEditorState
{
    /**
     * @param string[] $characters the UTF-8 characters of the buffer, `\n` between the lines of a multi-line entry
     * @param int $cursorIndex the index of the character the cursor stands before (the count of characters at the end)
     * @param string $status what the read loop does next
     */
    public function __construct(
        public readonly array $characters,
        public readonly int $cursorIndex,
        public readonly string $status = ConsoleLineEditor::STATUS_EDITING,
    ) {
    }

    /** @return string the buffer as one string */
    public function getText(): string
    {
        return implode('', $this->characters);
    }
}
