<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * countTrailingConsecutiveNudges keys on messageType=SIGNAL && signalSource=LOOP_NUDGE — NOT on the old
 * role=USER && authorType=AGENT heuristic. The load-bearing guarantee: a DIFFERENT out-of-band signal
 * (MEMORY_CONFLICT, FRONTEND_WIDGET_CLICK) in the trailing stream is NOT miscounted as a nudge — otherwise the
 * loop's stall recovery (MAX_STALL_SIGNALS) would mis-fire once a second source exists.
 * Pure domain (reflection-built, no DB/kernel).
 */
class AIConversationMessagesNudgeCountTest extends UnitTestCase
{
    /** @param AIConversationMessage[] $messageList */
    protected function messagesWith(array $messageList): AIConversationMessages
    {
        $messages = self::instantiateWithoutConstructor(AIConversationMessages::class);
        /** @var AIConversationMessages $messages */
        self::setPropertyValue($messages, 'elements', $messageList);
        return $messages;
    }

    protected function nudge(): AIConversationMessage
    {
        return $this->signal(AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE);
    }

    protected function signal(string $signalSource): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $message */
        self::setPropertyValue($message, 'messageType', AIConversationMessage::MESSAGE_SIGNAL);
        self::setPropertyValue($message, 'signalSource', $signalSource);
        self::setPropertyValue($message, 'role', AIConversationMessage::ROLE_SYSTEM);
        return $message;
    }

    protected function userText(): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $message */
        self::setPropertyValue($message, 'messageType', AIConversationMessage::MESSAGE_TEXT);
        self::setPropertyValue($message, 'role', AIConversationMessage::ROLE_USER);
        self::setPropertyValue($message, 'authorType', AIConversationMessage::AUTHOR_USER);
        return $message;
    }

    public function testCountsConsecutiveTrailingLoopNudges(): void
    {
        self::assertSame(2, $this->messagesWith([$this->userText(), $this->nudge(), $this->nudge()])
            ->countTrailingConsecutiveNudges());
    }

    public function testAProductiveTailBreaksTheRun(): void
    {
        self::assertSame(0, $this->messagesWith([$this->nudge(), $this->nudge(), $this->userText()])
            ->countTrailingConsecutiveNudges());
    }

    public function testForeignSignalAtTailIsNotCounted(): void
    {
        // a MEMORY_CONFLICT signal is NOT a nudge — it must not be counted (the regression guard).
        self::assertSame(0, $this->messagesWith([$this->nudge(), $this->signal('MEMORY_CONFLICT')])
            ->countTrailingConsecutiveNudges());
    }

    public function testForeignSignalBreaksTheConsecutiveRun(): void
    {
        // [nudge, MEMORY_CONFLICT, nudge] → only the trailing nudge counts; the foreign signal breaks the run.
        self::assertSame(1, $this->messagesWith([$this->nudge(), $this->signal('MEMORY_CONFLICT'), $this->nudge()])
            ->countTrailingConsecutiveNudges());
    }

    public function testOldRoleUserAuthorAgentShapeIsNoLongerCounted(): void
    {
        // The former nudge shape (role=USER + authorType=AGENT + TEXT) must NOT be counted any more —
        // proves the re-key to messageType=SIGNAL/signalSource, not a lingering role/author heuristic.
        $oldShape = self::instantiateWithoutConstructor(AIConversationMessage::class);
        /** @var AIConversationMessage $oldShape */
        self::setPropertyValue($oldShape, 'messageType', AIConversationMessage::MESSAGE_TEXT);
        self::setPropertyValue($oldShape, 'role', AIConversationMessage::ROLE_USER);
        self::setPropertyValue($oldShape, 'authorType', AIConversationMessage::AUTHOR_AGENT);
        self::assertSame(0, $this->messagesWith([$oldShape, $oldShape])->countTrailingConsecutiveNudges());
    }
}
