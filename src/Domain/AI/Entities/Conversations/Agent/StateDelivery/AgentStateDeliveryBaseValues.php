<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

/**
 * The two base-prompt values a `onceWhenChanged` conversation fills per state epoch instead of baking them into the
 * cached base prompt: `{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}`, resolved once per run
 * ({@see \DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService::resolveStateDeliveryBaseValues()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentStateDeliveryBaseValues
{
    /** @var string|null The context text of the space; null = the conversation has no space (the placeholder stays). */
    public ?string $agentSpaceContext = null;

    /** @var string The owner-addressing instruction; '' when the account carries no name (or it could not be resolved). */
    public string $ownerAddressing = '';

    /**
     * @var string[] The kinds ({@see AgentStateDelta::getKindOfAgentSpaceContext()}, {@see AgentStateDelta::KIND_OWNER_ADDRESSING})
     *      whose value could not be resolved (a cache, database or account read failed) — never compared, never mistaken
     *      for "no context text" or "no name".
     */
    public array $unknownKinds = [];

    public static function of(?string $agentSpaceContext, string $ownerAddressing): static
    {
        $agentStateDeliveryBaseValues = new static();
        $agentStateDeliveryBaseValues->agentSpaceContext = $agentSpaceContext;
        $agentStateDeliveryBaseValues->ownerAddressing = $ownerAddressing;
        return $agentStateDeliveryBaseValues;
    }
}
