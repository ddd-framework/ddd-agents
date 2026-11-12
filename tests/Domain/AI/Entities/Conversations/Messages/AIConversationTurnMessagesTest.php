<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Characterization of the AIConversationTurnMessages questions that had no test: the turn starts at
 * the newest initiator (rows before it never count), the counters and the signal reads over the turn, the initiator
 * (an index-less one included, unlike AIConversationMessages::getNewestInitiatorMessage()).
 */
class AIConversationTurnMessagesTest extends UnitTestCase
{
    public function testTheTurnStartsAtTheNewestInitiatorAndItsQuestionsReadOnlyItsRows(): void
    {
        $earlierOwner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'earlier', 0);
        $earlierCall = $this->call('getRankings', AIConversationMessage::STATUS_COMPLETED, 1);
        $earlierNudge = $this->signal(AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, 2);
        $owner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'now', 3);
        $failedTick = $this->call(SetTaskStatusTool::NAME, AIConversationMessage::STATUS_FAILED, 4, ['updates' => [['task' => '3.1', 'status' => 'done']]]);
        $loadTool = $this->call(LoadToolTool::NAME, AIConversationMessage::STATUS_COMPLETED, 5);
        $pendingRead = $this->call('getRankings', AIConversationMessage::STATUS_PENDING, 6);
        $pendingRead->toolCall->injectionScreening = ToolCall::INJECTION_SCREENING_PENDING;
        $pendingRead->id = 906;
        $nudge = $this->signal(AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, 7);
        $settled = $this->signal(AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED, 8);
        $injectionRemoved = $this->signal(AIConversationMessage::SIGNAL_SOURCE_INJECTION_REMOVED, 9);
        $turn = $this->messages($earlierOwner, $earlierCall, $earlierNudge, $owner, $failedTick, $loadTool, $pendingRead, $nudge, $settled, $injectionRemoved)
            ->buildCurrentTurn();

        self::assertSame($owner, $turn->getInitiatorMessage());
        self::assertCount(7, $turn->getRows(), 'the initiator and the rows after it');
        self::assertSame(1, $turn->countToolCallsNamed('getRankings'), 'the earlier turn\'s call does not count');
        self::assertSame(0, $turn->countToolCallsNamed('getRankings', true), 'COMPLETED-only');
        self::assertSame(1, $turn->countLoopNudges());
        self::assertTrue($turn->hasTaskPlanTickNamingTask('3.1'), 'a FAILED tick names its task too');
        self::assertFalse($turn->hasTaskPlanTickNamingTask('3.2'));
        self::assertTrue($turn->hasFailedTaskPlanTick());
        self::assertTrue($turn->hasSkillOrToolLoadCall(), 'load_tool counts');
        self::assertTrue($turn->hasCompletedToolCallNamed(LoadToolTool::NAME));
        self::assertFalse($turn->hasCompletedToolCallNamed('getRankings'), 'the completed one is in the earlier turn');
        self::assertTrue($turn->hasDelegationsSettledSignal());
        self::assertSame(1, $turn->countInjectionRemovedSignals());
        self::assertSame([$pendingRead], $turn->getCallRowsWithPendingInjectionScreening());
        self::assertNull($turn->getInitiatorWhenOwnerFormSubmit(), 'a text initiator is no form submit');
    }

    public function testWithoutAnInitiatorTheWholeSetIsTheTurnAndAnIndexLessInitiatorCounts(): void
    {
        $call = $this->call('getRankings', AIConversationMessage::STATUS_COMPLETED, 0);
        self::assertSame([$call], $this->messages($call)->buildCurrentTurn()->getRows());
        self::assertNull($this->messages($call)->buildCurrentTurn()->getInitiatorMessage());

        $transient = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'typed', 1);
        $messages = $this->messages($call, $transient);
        $transient->indexInConversation = null; // add() numbers a row; a transient initiator may still lack one
        self::assertSame($transient, $messages->buildCurrentTurn()->getInitiatorMessage());
        self::assertNull($messages->getNewestInitiatorMessage(), 'the set-level twin skips an index-less initiator (D3)');
    }

    public function testAViewKeepsItsRowsAndANewBuildSeesTheAppendedRow(): void
    {
        $messages = $this->messages($this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'go', 0));
        $turn = $messages->buildCurrentTurn();
        $appended = $this->call('getRankings', AIConversationMessage::STATUS_COMPLETED, 1);
        $messages->add($appended);
        self::assertSame(0, $turn->countToolCallsNamed('getRankings'), 'a built view never sees a later row');
        self::assertSame(1, $messages->buildCurrentTurn()->countToolCallsNamed('getRankings'), 'build it at each question');
    }

    public function testTheFailedSignatureCountReadsOnlyThisTurnsToolErrors(): void
    {
        $earlierFailure = $this->call('buildAndPreview', AIConversationMessage::STATUS_FAILED, 1, ['runId' => 7]);
        $failure = $this->call('buildAndPreview', AIConversationMessage::STATUS_FAILED, 3, ['runId' => 7]);
        $gateRefusal = $this->call('buildAndPreview', AIConversationMessage::STATUS_FAILED, 4, ['runId' => 7]);
        $gateRefusal->toolCall->wasRefusedByGate = true;
        $completed = $this->call('buildAndPreview', AIConversationMessage::STATUS_COMPLETED, 5, ['runId' => 7]);
        $otherArguments = $this->call('buildAndPreview', AIConversationMessage::STATUS_FAILED, 6, ['runId' => 8]);
        $history = $this->messages(
            $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'build', 0),
            $earlierFailure,
            $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'again', 2),
            $failure,
            $gateRefusal,
            $completed,
            $otherArguments
        )->buildCurrentTurn()->buildToolCallHistory();
        $signature = $failure->toolCall->signature();

        self::assertSame(1, $history->countFailedCallsWithSignature($signature), 'the earlier turn, a gate refusal, a completed call and other arguments never count');
        self::assertSame(2, $history->countCallsWithSignature($signature), 'the repeat count takes every status of this turn, never a gate refusal');
    }

    public function testABoundedTurnEndsBeforeTheNextInitiatorAndAnEndingTurnAtTheGivenMessage(): void
    {
        $first = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'first', 0);
        $firstCall = $this->call('getRankings', AIConversationMessage::STATUS_COMPLETED, 1);
        $report = $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'paused', 2);
        $second = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'continue', 3);
        $secondCall = $this->call('getProfile', AIConversationMessage::STATUS_COMPLETED, 4);
        $messages = $this->messages($first, $firstCall, $report, $second, $secondCall);

        self::assertSame([$first, $firstCall, $report], $messages->buildTurnStartingAtMessageIndex(0)->getRows(), 'up to the next initiator, excluded');
        self::assertSame([$second, $secondCall], $messages->buildTurnStartingAtMessageIndex(3)->getRows());
        self::assertSame([$first, $firstCall, $report], $messages->buildTurnStartingAtMessageIndex(99)->getRows(), 'an unknown index starts at the first row');
        self::assertSame([$first, $firstCall], $messages->buildTurnEndingAtMessageIndex(1)->getRows(), 'from the turn\'s initiator up to the message, included');
        self::assertSame([$second], $messages->buildTurnEndingAtMessageIndex(3)->getRows());
        self::assertSame([], $this->messages($firstCall)->buildTurnEndingAtMessageIndex(1)->getRows(), 'no initiator before it — empty, not the whole set');
    }

    protected function text(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function signal(string $signalSource, int $index): AIConversationMessage
    {
        $message = $this->text(AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'signal', $index);
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->signalSource = $signalSource;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function call(string $toolName, string $status, int $index, array $arguments = []): AIConversationMessage
    {
        $message = $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = $status;
        $toolCall->arguments = $arguments;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function messages(AIConversationMessage ...$rows): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        return $messages;
    }
}
