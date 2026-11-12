<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * AIConversationToolCallHistory::hasEarlierCompletedCallWithIdenticalArguments — the evidence behind the delete-retry
 * rewrite: a failed delete counts as success only when an identical call really finished earlier. A prior call that
 * COMPLETED but declared it did not finish (`outcome: incomplete`, `applied: false`) proves nothing.
 * Container-less (reflection-built); pure domain logic.
 */
class AIConversationMessagesDeleteRetryTest extends TestCase
{
    public function testAnIdenticalCompletedCallIsEvidence(): void
    {
        $messages = $this->messagesWith($this->toolCallMessage('deleteLogos', ['fileIds' => ['a']], null, 7));

        $this->assertTrue($messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($this->toolCall('deleteLogos', ['fileIds' => ['a']])));
    }

    public function testDifferentArgumentsAreNoEvidence(): void
    {
        $messages = $this->messagesWith($this->toolCallMessage('deleteLogos', ['fileIds' => ['a']], null, 7));

        $this->assertFalse($messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($this->toolCall('deleteLogos', ['fileIds' => ['b']])));
    }

    public function testACompletedCallThatDeclaredItselfIncompleteIsNoEvidence(): void
    {
        $messages = $this->messagesWith(
            $this->toolCallMessage('deleteLogos', ['fileIds' => ['a']], '{"structuredContent":{"outcome":"incomplete"}}', 7)
        );

        $this->assertFalse($messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($this->toolCall('deleteLogos', ['fileIds' => ['a']])));
    }

    public function testACompletedCallThatAppliedNothingIsNoEvidence(): void
    {
        $messages = $this->messagesWith(
            $this->toolCallMessage('deleteLogos', ['fileIds' => ['a']], '{"structuredContent":{"applied":false}}', 7)
        );

        $this->assertFalse($messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($this->toolCall('deleteLogos', ['fileIds' => ['a']])));
    }

    protected function messagesWith(AIConversationMessage ...$elements): AIConversationMessages
    {
        $messages = (new ReflectionClass(AIConversationMessages::class))->newInstanceWithoutConstructor();
        $addAsChild = new ReflectionProperty($messages, 'addAsChild');
        $addAsChild->setValue($messages, false);
        foreach ($elements as $element) {
            $messages->add($element);
        }
        return $messages;
    }

    /** @param array<string, mixed> $arguments */
    protected function toolCallMessage(string $name, array $arguments, ?string $result, int $indexInConversation): AIConversationMessage
    {
        $message = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->indexInConversation = $indexInConversation;
        $toolCall = $this->toolCall($name, $arguments);
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        if ($result !== null) {
            $toolCall->result = $result;
        }
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function toolCall(string $name, array $arguments): ToolCall
    {
        $toolCall = (new ReflectionClass(ToolCall::class))->newInstanceWithoutConstructor();
        $toolCall->name = $name;
        $toolCall->arguments = $arguments;
        return $toolCall;
    }
}
