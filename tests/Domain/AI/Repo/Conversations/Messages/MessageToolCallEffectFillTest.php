<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A message row stored before tool calls carried their effect is filled from the code
 * metadata as it is loaded; a stamped row — a declared "none" included — keeps what it was stamped with.
 */
class MessageToolCallEffectFillTest extends UnitTestCase
{
    public function testAnUnstampedRowGetsItsToolsEffectAndAStampedRowKeepsItsOwn(): void
    {
        $legacyMessage = $this->messageWithToolCall('updateProfileCategories', null);
        $this->fill($legacyMessage);
        self::assertEquals(MCPToolEffectCatalog::getEffectForToolName('updateProfileCategories'), $legacyMessage->toolCall->getEffect());

        $stampedAsRead = new ToolCallEffect();
        $stampedAsRead->effect = MCPToolEffect::READ;
        $stampedMessage = $this->messageWithToolCall('updateProfileCategories', $stampedAsRead);
        $this->fill($stampedMessage);
        self::assertSame(MCPToolEffect::READ, $stampedMessage->toolCall->getEffect()?->effect, 'the kind it ran with, not today\'s');

        $declaredNone = $this->messageWithToolCall('delegate', ToolCallEffect::fromMcpToolEffect(null));
        $this->fill($declaredNone);
        self::assertNull($declaredNone->toolCall->getEffect());
        self::assertSame(ToolCallEffect::NONE, $declaredNone->toolCall->effect?->effect);
    }

    protected function messageWithToolCall(string $toolName, ?ToolCallEffect $effect): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->effect = $effect;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** The fill step {@see DBAIConversationMessage::mapToEntity()} runs on every hydrated message. */
    protected function fill(AIConversationMessage $message): void
    {
        (new \ReflectionMethod(DBAIConversationMessage::class, 'fillUnstampedToolCallEffect'))->invoke(null, $message);
    }
}
