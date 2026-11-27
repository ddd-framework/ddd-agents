<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * {@see AIConversationMessages::getNewestIndexOfRowWrittenByTool()} counts only a TOOL_MESSAGE authored by TOOL whose
 * row label is the tool's name: a row of another tool, a signal with the same label and the model's own text never
 * count.
 */
class AIConversationMessagesNewestRowWrittenByToolTest extends UnitTestCase
{
    protected function message(int $index, string $type, ?string $authorType, ?string $signalSource): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = $type;
        $message->authorType = $authorType;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    protected function set(array $messages): AIConversationMessages
    {
        $set = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($set, 'elements', $messages);
        self::setPropertyValue($set, 'elementCount', count($messages));
        return $set;
    }

    public function testTheNewestRowOfTheToolIsFound(): void
    {
        $set = $this->set([
            $this->message(4, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL, 'deliverReport'),
            $this->message(9, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL, 'deliverReport'),
            $this->message(7, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL, 'deliverReport'),
        ]);

        self::assertSame(9, $set->getNewestIndexOfRowWrittenByTool('deliverReport'));
    }

    public function testARowOfAnotherToolASignalAndTheModelsOwnTextDoNotCount(): void
    {
        $set = $this->set([
            $this->message(3, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL, 'deliverReport'),
            $this->message(5, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_TOOL, 'anotherTool'),
            $this->message(6, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::AUTHOR_AGENT, 'deliverReport'),
            $this->message(7, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::AUTHOR_ASSISTANT, null),
            $this->message(8, AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::AUTHOR_ASSISTANT, 'deliverReport'),
        ]);

        self::assertSame(3, $set->getNewestIndexOfRowWrittenByTool('deliverReport'));
    }

    public function testNullWhenTheLoadedSetHoldsNoRowOfTheTool(): void
    {
        $set = $this->set([
            $this->message(2, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::AUTHOR_ASSISTANT, null),
        ]);

        self::assertNull($set->getNewestIndexOfRowWrittenByTool('deliverReport'));
        self::assertNull($this->set([])->getNewestIndexOfRowWrittenByTool('deliverReport'));
    }
}
