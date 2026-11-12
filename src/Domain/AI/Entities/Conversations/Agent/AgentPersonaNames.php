<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two names the agent's prompts carry: what the agent is called and what the product it works in is called. Read
 * from the application's persona config with the built-in defaults by
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::findAgentPersonaNames()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentPersonaNames extends ValueObject
{
    /** @var string The name of the agent */
    public string $agentName = '';

    /** @var string The name of the product the agent works in */
    public string $productName = '';

    /**
     * @param string $agentName
     * @param string $productName
     * @return static
     */
    public static function forAgentAndProductName(string $agentName, string $productName): static
    {
        $agentPersonaNames = new static();
        $agentPersonaNames->agentName = $agentName;
        $agentPersonaNames->productName = $productName;
        return $agentPersonaNames;
    }
}
