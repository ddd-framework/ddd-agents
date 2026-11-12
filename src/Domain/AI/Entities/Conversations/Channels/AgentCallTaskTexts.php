<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Channels;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the trace view needs of a conversation's calls: whether it has any call, and the task texts the agent gave its
 * calls (a message that repeats a task text was written for the call). Built by the voice channel; empty without one.
 */
class AgentCallTaskTexts extends ValueObject
{
    /** @var bool Whether the conversation has at least one call. */
    public bool $hasCalls = false;

    /** @var string[] The non-empty, trimmed task texts of the conversation's calls, in call order. */
    public array $taskTexts = [];
}
