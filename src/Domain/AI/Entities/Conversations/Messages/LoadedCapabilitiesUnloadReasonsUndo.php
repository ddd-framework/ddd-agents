<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Gives a resolved capability set back the unload reasons a written change record took into its events — queued as an
 * undo of a claim-fenced write that may still roll back (the typed undo queue).
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesUnloadReasonsUndo implements AIConversationMessageWriteUndo
{
    protected LoadedCapabilities $resolvedSet;

    /** @var array<string, string> capability name → the reason it was unloaded */
    protected array $unloadReasons = [];

    /** @param array<string, string> $unloadReasons capability name → the reason it was unloaded */
    public static function forUnloadReasons(LoadedCapabilities $resolvedSet, array $unloadReasons): static
    {
        $unloadReasonsUndo = new static();
        $unloadReasonsUndo->resolvedSet = $resolvedSet;
        $unloadReasonsUndo->unloadReasons = $unloadReasons;
        return $unloadReasonsUndo;
    }

    /** Record the unload reasons on the resolved set again. */
    public function undo(): void
    {
        foreach ($this->unloadReasons as $unloadedName => $unloadReason) {
            $this->resolvedSet->recordUnloadReason($unloadReason, (string)$unloadedName);
        }
    }
}
