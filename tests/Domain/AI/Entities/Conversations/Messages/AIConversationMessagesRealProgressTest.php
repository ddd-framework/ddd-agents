<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * hasNonNudgeMessageAfterIndex() is the "did this resume turn make REAL progress" test that BOUNDS the trailing-
 * LOOP_NUDGE re-fire ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady}):
 * a turn that appended ONLY LOOP_NUDGE signals (a stalling model) made no real progress and must count toward
 * MAX_RESUME_ATTEMPTS instead of resetting it. A real answer / tool call / NON-LOOP_NUDGE signal (USER / widget-click /
 * memory-conflict) IS progress. INDEX-based (not a count delta) so it survives a bounded/compacted tail reload. Keys on
 * messageType=SIGNAL && signalSource=LOOP_NUDGE — never text (mirrors countTrailingConsecutiveNudges). Pure domain.
 */
class AIConversationMessagesRealProgressTest extends UnitTestCase
{
    /** @param AIConversationMessage[] $messageList */
    protected function messagesWith(array $messageList): AIConversationMessages
    {
        $messages = self::instantiateWithoutConstructor(AIConversationMessages::class);
        /** @var AIConversationMessages $messages */
        self::setPropertyValue($messages, 'elements', $messageList);
        return $messages;
    }

    protected function nudgeAt(int $index): AIConversationMessage
    {
        return $this->signalAt($index, AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE);
    }

    protected function signalAt(int $index, string $signalSource): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $message */
        self::setPropertyValue($message, 'indexInConversation', $index);
        self::setPropertyValue($message, 'messageType', AIConversationMessage::MESSAGE_SIGNAL);
        self::setPropertyValue($message, 'signalSource', $signalSource);
        self::setPropertyValue($message, 'role', AIConversationMessage::ROLE_SYSTEM);
        return $message;
    }

    protected function assistantAt(int $index): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $message */
        self::setPropertyValue($message, 'indexInConversation', $index);
        self::setPropertyValue($message, 'messageType', AIConversationMessage::MESSAGE_TEXT);
        self::setPropertyValue($message, 'role', AIConversationMessage::ROLE_ASSISTANT);
        self::setPropertyValue($message, 'authorType', AIConversationMessage::AUTHOR_ASSISTANT);
        return $message;
    }

    protected function toolCallAt(int $index): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $message */
        self::setPropertyValue($message, 'indexInConversation', $index);
        self::setPropertyValue($message, 'messageType', AIConversationMessage::MESSAGE_TOOL_CALL);
        self::setPropertyValue($message, 'authorType', AIConversationMessage::AUTHOR_ASSISTANT);
        return $message;
    }

    public function testAssistantAnswerAfterIndexIsProgress(): void
    {
        self::assertTrue($this->messagesWith([$this->assistantAt(5)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testOnlyANudgeAfterIndexIsNotProgress(): void
    {
        self::assertFalse($this->messagesWith([$this->nudgeAt(5)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testNothingAfterIndexIsNotProgress(): void
    {
        // The delta is empty (a claim-lost silent abort appended nothing) → no progress.
        self::assertFalse($this->messagesWith([$this->assistantAt(3)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testNudgeThenAnswerIsProgress(): void
    {
        self::assertTrue($this->messagesWith([$this->nudgeAt(5), $this->assistantAt(6)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testTwoNudgesOnlyIsNotProgress(): void
    {
        self::assertFalse($this->messagesWith([$this->nudgeAt(5), $this->nudgeAt(6)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testToolCallAfterIndexIsProgress(): void
    {
        // A multi-step tool turn (no assistant text yet) must count as progress — never falsely capped.
        self::assertTrue($this->messagesWith([$this->toolCallAt(5)])->hasNonNudgeMessageAfterIndex(4));
    }

    public function testNonLoopNudgeSignalAfterIndexIsProgress(): void
    {
        // A widget-click / memory-conflict signal is NOT a LOOP_NUDGE → it is real inbound progress.
        self::assertTrue($this->messagesWith([$this->signalAt(5, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK)])
            ->hasNonNudgeMessageAfterIndex(4));
    }

    public function testOldNonNudgeMessageAtOrBelowIndexIsIgnored(): void
    {
        // An assistant answer from a PRIOR turn (index <= afterIndex) must NOT be mistaken for this turn's progress;
        // only the trailing nudge at index 5 is in-delta → no progress.
        self::assertFalse($this->messagesWith([$this->assistantAt(3), $this->nudgeAt(5)])->hasNonNudgeMessageAfterIndex(4));
    }
}
