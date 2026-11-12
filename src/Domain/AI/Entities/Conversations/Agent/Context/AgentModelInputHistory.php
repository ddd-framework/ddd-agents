<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The HISTORY phase of a model input: the conversation's messages up to the anchor (inclusive), cut at
 * the compaction cover's edge with the cover's rendered summary in front, the tool-result retention verdict over that
 * tail, and the loaded capabilities of the newest message in it that carries a set. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildModelInputHistoryUpToAndIncludingMessage()};
 * the context build copies its fields. Never persisted.
 */
class AgentModelInputHistory extends ValueObject
{
    /** @var AIConversationMessage[] The messages after the cover edge, up to the anchor (inclusive), in index order. */
    public array $messages = [];

    /** The compaction cover's rendered summary of everything before {@see $messages}; null when nothing in range is compacted. */
    public ?string $compactionSummary = null;

    /** Tool results on a message below this index are stubbed on the wire; `PHP_INT_MIN` = keep all. */
    public int $keepToolResultsFromIndex = PHP_INT_MIN;

    /** @var array<int, true> Message indexes at or above {@see $keepToolResultsFromIndex} whose result the token budget could not hold. */
    public array $toolResultIndexesStubbedByBudget = [];

    /** The cumulative loaded capabilities of {@see $capabilityAnchorMessage}; null when no message in range carries a set. */
    public ?LoadedCapabilities $loadedCapabilities = null;

    /** The resolved skills of {@see $loadedCapabilities}; null when no message in range carries a set. */
    public ?AISkills $loadedAISkills = null;

    /** The newest message in {@see $messages} that carries a loaded-capability set; null when none does. */
    public ?AIConversationMessage $capabilityAnchorMessage = null;
}
