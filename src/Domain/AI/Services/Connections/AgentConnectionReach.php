<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Which releases count when an account reaches a connection. In a conversation only a release to the conversation's
 * own space counts (what the agent reads there is seen by that space's readers); the owner reaches its own connection
 * without a release only in a conversation nobody else reads. The management screens and approval answers see the
 * releases of every space the account is a member of.
 */
#[Exclude]
class AgentConnectionReach
{
    public ?int $agentSpaceId = null;

    /** @var int|null The conversation the reach serves; whether others read it decides the owner's own access */
    public ?int $aIConversationId = null;

    public bool $acrossMemberSpaces = false;

    /** The reach of a tool call: its conversation's space and the conversation itself */
    public static function ofCall(?AgentToolCallProvenance $callProvenance): static
    {
        $agentConnectionReach = static::inSpace($callProvenance?->agentSpaceId);
        $agentConnectionReach->aIConversationId = $callProvenance?->conversationId ?? $callProvenance?->rootConversationId;
        return $agentConnectionReach;
    }

    /** Work of `$agentSpaceId` outside a conversation (a trigger); null = no space (only own connections) */
    public static function inSpace(?int $agentSpaceId): static
    {
        $agentConnectionReach = new static();
        $agentConnectionReach->agentSpaceId = $agentSpaceId;
        return $agentConnectionReach;
    }

    /** The management view: every space the account is a member of */
    public static function acrossMemberSpaces(): static
    {
        $agentConnectionReach = new static();
        $agentConnectionReach->acrossMemberSpaces = true;
        return $agentConnectionReach;
    }

    /** Whether a release to `$releasedAgentSpaceId` (of which the account is a member) counts for this reach */
    public function admitsReleaseTo(int $releasedAgentSpaceId): bool
    {
        return $this->acrossMemberSpaces || ($this->agentSpaceId !== null && $this->agentSpaceId === $releasedAgentSpaceId);
    }
}
