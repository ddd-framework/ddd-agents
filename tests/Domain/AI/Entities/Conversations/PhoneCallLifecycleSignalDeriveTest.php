<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Guards the phone-call lifecycle see-through on BOTH turn derivations: a PHONE_CALL_STARTED/ENDED signal is a
 * chat chip + structural context, never owed work — while every OTHER trailing signal keeps demanding a turn
 * and an unanswered USER message stays owed even when a call marker trails it.
 */
class PhoneCallLifecycleSignalDeriveTest extends UnitTestCase
{
    protected function buildConversationWithMessages(array $messageBlueprints): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        foreach ($messageBlueprints as $messageBlueprint) {
            $message = new AIConversationMessage();
            $conversation->appendMessage(
                $message,
                $messageBlueprint['messageType'],
                $messageBlueprint['content'] ?? 'x',
                role: $messageBlueprint['role'] ?? null,
                signalSource: $messageBlueprint['signalSource'] ?? null
            );
        }

        return $conversation;
    }

    public function testCallEndMarkerAfterFinalAnswerOwesNoTurn(): void
    {
        $conversation = $this->buildConversationWithMessages([
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_USER],
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_ASSISTANT],
            ['messageType' => AIConversationMessage::MESSAGE_SIGNAL, 'role' => AIConversationMessage::ROLE_SYSTEM,
                'signalSource' => PhoneConversationSignalSource::PHONE_CALL_ENDED->value],
        ]);
        self::assertFalse($conversation->hasUnansweredInboundMessage());
        self::assertTrue($conversation->lastWireMessageIsFinalAssistantText());
    }

    public function testStartAndEndMarkersTogetherOweNoTurn(): void
    {
        $conversation = $this->buildConversationWithMessages([
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_USER],
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_ASSISTANT],
            ['messageType' => AIConversationMessage::MESSAGE_SIGNAL, 'role' => AIConversationMessage::ROLE_SYSTEM,
                'signalSource' => PhoneConversationSignalSource::PHONE_CALL_STARTED->value],
            ['messageType' => AIConversationMessage::MESSAGE_SIGNAL, 'role' => AIConversationMessage::ROLE_SYSTEM,
                'signalSource' => PhoneConversationSignalSource::PHONE_CALL_ENDED->value],
        ]);
        self::assertFalse($conversation->hasUnansweredInboundMessage());
        self::assertTrue($conversation->lastWireMessageIsFinalAssistantText());
    }

    public function testUnansweredUserStaysOwedThroughTrailingCallMarker(): void
    {
        $conversation = $this->buildConversationWithMessages([
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_USER],
            ['messageType' => AIConversationMessage::MESSAGE_SIGNAL, 'role' => AIConversationMessage::ROLE_SYSTEM,
                'signalSource' => PhoneConversationSignalSource::PHONE_CALL_STARTED->value],
        ]);
        self::assertTrue($conversation->hasUnansweredInboundMessage());
        self::assertFalse($conversation->lastWireMessageIsFinalAssistantText());
    }

    public function testOtherTrailingSignalsStillDemandATurn(): void
    {
        $conversation = $this->buildConversationWithMessages([
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_USER],
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_ASSISTANT],
            ['messageType' => AIConversationMessage::MESSAGE_SIGNAL, 'role' => AIConversationMessage::ROLE_SYSTEM,
                'signalSource' => AIConversationMessage::SIGNAL_SOURCE_DASHBOARD_WIDGET_CONTEXT],
        ]);
        self::assertTrue($conversation->hasUnansweredInboundMessage());
        self::assertFalse($conversation->lastWireMessageIsFinalAssistantText());
    }

    public function testAnsweredConversationWithoutMarkersIsUnchanged(): void
    {
        $conversation = $this->buildConversationWithMessages([
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_USER],
            ['messageType' => AIConversationMessage::MESSAGE_TEXT, 'role' => AIConversationMessage::ROLE_ASSISTANT],
        ]);
        self::assertFalse($conversation->hasUnansweredInboundMessage());
        self::assertTrue($conversation->lastWireMessageIsFinalAssistantText());
    }
}
