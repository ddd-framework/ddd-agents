<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The owner's standing directives that apply to one space of an account (without a space: the account-wide ones
 * only), as the memory component renders them for the system prompt, and when the newest of them became valid (the
 * reply-language decision weighs a directive against the conversation's anchor by age). Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService::findStandingDirectivesForAgentSpace()}.
 */
class AgentStandingDirectives extends ValueObject
{
    /** @var string The rendered directives block, as the context build injects it. */
    public string $directivesBlock = '';

    /** @var DateTime|null When the newest currently valid directive became valid; null when no directive carries a date. */
    public ?DateTime $newestValidFrom = null;
}
