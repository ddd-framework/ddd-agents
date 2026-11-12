<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes;

/** A tool request whose target space is an argument of the call. */
class ProbeRequestDtoNamingAnAgentSpace
{
    public ?int $agentSpaceId = null;

    public ?string $note = null;
}
