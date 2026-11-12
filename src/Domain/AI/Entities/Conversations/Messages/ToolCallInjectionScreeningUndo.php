<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Puts a tool call's injection-screening state back as it was before a claim-fenced write that rolled back — a
 * screening start that never committed leaves the call unscreened in memory too
 * (replaces the undo closure). Taken BEFORE the state changes.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallInjectionScreeningUndo implements AIConversationMessageWriteUndo
{
    protected ToolCall $toolCall;

    protected ?string $injectionScreeningBefore = null;

    /** The undo for the screening state `$toolCall` holds right now. */
    public static function ofToolCall(ToolCall $toolCall): static
    {
        $injectionScreeningUndo = new static();
        $injectionScreeningUndo->toolCall = $toolCall;
        $injectionScreeningUndo->injectionScreeningBefore = $toolCall->injectionScreening;
        return $injectionScreeningUndo;
    }

    /** Restore the tool call's screening state. */
    public function undo(): void
    {
        $this->toolCall->injectionScreening = $this->injectionScreeningBefore;
    }
}
