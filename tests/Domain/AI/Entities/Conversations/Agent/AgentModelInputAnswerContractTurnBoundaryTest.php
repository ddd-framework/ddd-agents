<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The answer contract's turn boundary: the final answer owes the outcomes of the writes since the owner's newest
 * input — their text, or their own signal (a widget submit, an action confirmation). The writes before an owner
 * signal were reported in the answer the owner acted on; a contract that lists them again makes the model repeat that
 * whole answer after a one-entry edit on a widget.
 */
class AgentModelInputAnswerContractTurnBoundaryTest extends UnitTestCase
{
    protected function buildOwnerTextMessage(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $ownerTextMessage */
        $ownerTextMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerTextMessage->role = AIConversationMessage::ROLE_USER;
        $ownerTextMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerTextMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerTextMessage->message = $text;
        return $ownerTextMessage;
    }

    protected function buildOwnerWidgetClickSignal(): AIConversationMessage
    {
        /** @var AIConversationMessage $ownerWidgetClickSignal */
        $ownerWidgetClickSignal = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerWidgetClickSignal->role = AIConversationMessage::ROLE_SYSTEM;
        $ownerWidgetClickSignal->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $ownerWidgetClickSignal->signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        $ownerWidgetClickSignal->message = 'The owner submitted the selection on a widget.';
        return $ownerWidgetClickSignal;
    }

    protected function buildCompletedToolCallMessage(string $toolName): AIConversationMessage
    {
        /** @var AIConversationMessage $toolCallMessage */
        $toolCallMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $toolCallMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = static::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCallMessage->toolCall = $toolCall;
        return $toolCallMessage;
    }

    public function testTheWritesBeforeAnOwnerSignalAreNotOwedAgain(): void
    {
        $ownerWidgetClickSignal = $this->buildOwnerWidgetClickSignal();
        self::assertTrue($ownerWidgetClickSignal->isOwnerAuthoredInbound(), 'the widget click is the owner\'s own signal');

        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [
            $this->buildOwnerTextMessage('Save the profile as drafted.'),
            $this->buildCompletedToolCallMessage('updateProfile'),
            $this->buildCompletedToolCallMessage('addCompetitors'),
            $ownerWidgetClickSignal,
            $this->buildCompletedToolCallMessage('updateCompetitors'),
        ];
        $answerContractHint = (string)$agentModelInput->completedWritesAnswerContractHint();

        self::assertStringContainsString('Completed so far in this turn: updateCompetitors.', $answerContractHint);
        self::assertStringNotContainsString('updateProfile', $answerContractHint);
        self::assertStringNotContainsString('addCompetitors', $answerContractHint);
    }

    public function testAnOwnerSignalWithoutAWriteAfterItOwesNoContract(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [
            $this->buildOwnerTextMessage('Save the profile as drafted.'),
            $this->buildCompletedToolCallMessage('updateProfile'),
            $this->buildOwnerWidgetClickSignal(),
        ];
        self::assertNull($agentModelInput->completedWritesAnswerContractHint());
    }

    public function testWithoutAnOwnerSignalTheOwnersTextStaysTheBoundary(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [
            $this->buildOwnerTextMessage('first'),
            $this->buildCompletedToolCallMessage('updateOpeningHours'),
            $this->buildOwnerTextMessage('and now the profile'),
            $this->buildCompletedToolCallMessage('updateProfile'),
        ];
        $answerContractHint = (string)$agentModelInput->completedWritesAnswerContractHint();

        self::assertStringContainsString('Completed so far in this turn: updateProfile.', $answerContractHint);
        self::assertStringNotContainsString('updateOpeningHours', $answerContractHint);
    }

    public function testTheContractNamesTheContentOfTheAnswerAndPutsOpenWorkFirst(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [
            $this->buildOwnerTextMessage('Change the scope and switch the automatic replies off.'),
            $this->buildCompletedToolCallMessage('grantMandate'),
        ];
        $answerContractHint = (string)$agentModelInput->completedWritesAnswerContractHint();

        self::assertStringContainsString('Your final answer MUST report the outcomes of these actions', $answerContractHint);
        self::assertStringContainsString('not when it is due', $answerContractHint);
        self::assertStringContainsString('still open comes first — make its call now', $answerContractHint);
    }
}
