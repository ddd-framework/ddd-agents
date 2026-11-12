<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The prompt-cache routing key ({@see ArgusAIAgentConversation::resolvePromptCacheRoutingKey()}), the value sent as the
 * request's prompt cache key: one key per space, the key of its account for a conversation without a space, none
 * without either. The space service builds it from the two ids the conversation carries.
 */
class ArgusAIAgentConversationPromptCacheRoutingKeyTest extends UnitTestCase
{
    protected function conversationOf(?int $accountId, ?int $agentSpaceId): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        if ($accountId !== null) {
            $conversation->accountId = $accountId;
        }
        if ($agentSpaceId !== null) {
            $conversation->agentSpaceId = $agentSpaceId;
        }
        return $conversation;
    }

    public function testAConversationOfASpaceIsRoutedByTheSpace(): void
    {
        $routingKey = $this->conversationOf(91, 7)->resolvePromptCacheRoutingKey();
        self::assertSame(AgentSpacesService::PROMPT_CACHE_ROUTING_KEY_PREFIX_OF_AGENT_SPACE . '7', $routingKey);
        self::assertSame('agent-space-7', $routingKey);
    }

    public function testAConversationWithoutASpaceIsRoutedByItsAccount(): void
    {
        $routingKey = (string)$this->conversationOf(91, null)->resolvePromptCacheRoutingKey();
        self::assertSame('agent-account-91', $routingKey);
    }

    public function testTwoSpacesOfOneAccountDoNotShareAKey(): void
    {
        self::assertNotSame(
            $this->conversationOf(91, 7)->resolvePromptCacheRoutingKey(),
            $this->conversationOf(91, 8)->resolvePromptCacheRoutingKey()
        );
    }

    public function testWithoutAnAccountAndWithoutASpaceThereIsNoKey(): void
    {
        self::assertNull($this->conversationOf(null, null)->resolvePromptCacheRoutingKey());
    }
}
