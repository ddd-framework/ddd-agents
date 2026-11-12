<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * The injection screening's verdict on one stored tool result: what the screening job found, handed to
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::persistToolCallVerdictWhileScreeningPending()},
 * which applies it to the row's CURRENT call under the row lock (a value object instead of a closure).
 *
 * @phpstan-consistent-constructor
 */
class ToolCallInjectionScreeningVerdict
{
    /** Whether a chunk's injection probability reached the threshold. */
    public bool $flagsInjection = false;

    /** Whether a flagged result is removed (the site mode `on`) rather than only recorded. */
    public bool $removesFlaggedResult = false;

    /** The highest injection probability any chunk carried; null when no chunk was answered. */
    public ?float $highestInjectionProbability = null;

    /** Whether the decision model answered every chunk. */
    public bool $everyChunkAnswered = false;

    public static function fromScreening(bool $flagsInjection, bool $removesFlaggedResult, ?float $highestInjectionProbability, bool $everyChunkAnswered): static
    {
        $injectionScreeningVerdict = new static();
        $injectionScreeningVerdict->flagsInjection = $flagsInjection;
        $injectionScreeningVerdict->removesFlaggedResult = $removesFlaggedResult;
        $injectionScreeningVerdict->highestInjectionProbability = $highestInjectionProbability;
        $injectionScreeningVerdict->everyChunkAnswered = $everyChunkAnswered;
        return $injectionScreeningVerdict;
    }

    /** Apply the verdict to a call ({@see ToolCall::applyInjectionScreeningVerdict()}). */
    public function applyTo(ToolCall $toolCall): void
    {
        $toolCall->applyInjectionScreeningVerdict($this->flagsInjection, $this->removesFlaggedResult, $this->highestInjectionProbability, $this->everyChunkAnswered);
    }
}
