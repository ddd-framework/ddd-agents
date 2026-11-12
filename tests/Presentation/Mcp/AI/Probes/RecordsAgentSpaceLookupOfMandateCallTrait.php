<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;

/**
 * Answers the space of a mandate call in place of the space service and keeps the arguments the tool handed over
 * (the question the space service is asked).
 */
trait RecordsAgentSpaceLookupOfMandateCallTrait
{
    /** @var array<int, array<string, mixed>> the arguments naming the space, one entry per lookup */
    public array $toolArgumentsOfAgentSpaceLookups = [];

    /** @var AgentSpace|null the space the lookup answers */
    public ?AgentSpace $agentSpaceTheLookupAnswers = null;

    protected function findAgentSpaceOfMandateCall(array $toolArgumentsNamingTheAgentSpace, AgentToolCallProvenance $agentToolCallProvenance): ?AgentSpace
    {
        $this->toolArgumentsOfAgentSpaceLookups[] = $toolArgumentsNamingTheAgentSpace;
        return $this->agentSpaceTheLookupAnswers;
    }
}
