<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\NotificationTurns;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRunEndQuietWait;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentAfterStepRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedToolsInputs;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService;

/**
 * The turn that sub-agent notifications started, pure:
 *
 * - which turn counts as opened by notifications only (`AIConversationTurnMessages::isOpenedBySubAgentNotificationsOnly()`),
 *   and that the stall / checked-stop / turn-closing exemptions read it like the legacy DELEGATIONS_SETTLED turn
 *   (`readsSubAgentResults()`);
 * - the stall text of such a turn (answer, continue, or `wait_for_sub_agents` when it is offered);
 * - `wait_for_sub_agents`: its answers, its after-step request, its offer, and both owed-work walks reading its
 *   completed call as the turn's end (`isTurnEndWithoutTextCall()`);
 * - the 2 s quiet / 8 s cap decision of the short wait (`AgentSubAgentRunEndQuietWait`);
 * - the release and the loop stop reason of a turn that ended waiting.
 */
class NotificationStartedTurnTest extends UnitTestCase
{
    // ---------------------------------------------------------------------------------------------------------------
    // the turn opened by notifications only
    // ---------------------------------------------------------------------------------------------------------------

    public function testANotificationAfterTheOwnerTurnsAnswerOpensANotificationTurn(): void
    {
        $messages = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME, 4)
        );
        $turn = $messages->buildCurrentTurn();

        self::assertTrue($turn->isOpenedBySubAgentNotificationsOnly());
        self::assertTrue($turn->readsSubAgentResults());
        self::assertFalse($turn->hasDelegationsSettledSignal());
        self::assertFalse($turn->hasModelCallSinceTheNewestTurnEnd(), 'the head of the stretch: no model call yet');
    }

    public function testTheTurnStaysOneAfterItsFirstModelCallsAndTheLoopsOwnRows(): void
    {
        $nudge = $this->signal(AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE, 6);
        $nudge->inputTokens = 900; // a stalled step's usage rides its nudge
        $messages = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE, 4), // the purpose's turn-start notice
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_PLAN_BOOKKEEPING, 5),
            $nudge,
            $this->call(CheckDelegationsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 7)
        );

        self::assertTrue($messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly());
        self::assertTrue($messages->buildCurrentTurn()->hasModelCallSinceTheNewestTurnEnd(), 'a chunk continuation never waits again');
    }

    public function testAnOwnerTurnAMidTurnNotificationAndAMixedOpeningAreNoNotificationTurn(): void
    {
        $ownerTurn = $this->messages(
            $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Check my three competitors.', 0),
            $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 1),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 2), // handed over mid-turn
            $this->call(CheckDelegationsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 3)
        );
        self::assertFalse($ownerTurn->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'the owner opened it');
        self::assertFalse($ownerTurn->buildCurrentTurn()->readsSubAgentResults());

        $withClick = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 4)
        );
        self::assertFalse($withClick->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'an owner click opened it too');

        $withQuotedOwnerRow = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_MID_TURN_OWNER_INBOUND, 4)
        );
        self::assertFalse($withQuotedOwnerRow->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'a note quoting the owner carries owner input');

        $onlyWithoutResume = $this->answeredOwnerTurnFollowedBy($this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME, 3));
        self::assertFalse($onlyWithoutResume->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'a run end that does not resume opens no turn');

        $ownerAfterNotification = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'And the prices?', 4)
        );
        self::assertFalse($ownerAfterNotification->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'the owner wrote: an owner turn');
    }

    public function testALegacyDelegationsSettledTurnStillReadsSubAgentResults(): void
    {
        $messages = $this->answeredOwnerTurnFollowedBy($this->signal(AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED, 3));
        self::assertFalse($messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly());
        self::assertTrue($messages->buildCurrentTurn()->readsSubAgentResults(), 'legacy rows keep their exemptions until 103b-9');
    }

    public function testANotificationAfterAWaitCallOpensTheNextStretch(): void
    {
        $messages = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 4),
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 5)
        );
        self::assertTrue($messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly());
        self::assertFalse($messages->buildCurrentTurn()->hasModelCallSinceTheNewestTurnEnd(), 'a wait call is a turn end');
    }

    /**
     * A turn the platform ended to wait for the sub-agents a tool asked for (the website pages,
     * the day-context look-ups) ends with the platform's own COMPLETED `wait_for_sub_agents` row — so the notification
     * after it opens a notification turn, which is offered the wait tool.
     */
    public function testANotificationAfterTheParkOfARequestingToolOpensANotificationTurnThatIsOfferedTheWait(): void
    {
        foreach (['writeAllPendingPages' => 3, 'startDayContextLookUps' => 2] as $requestingToolName => $subAgentCount) {
            $seed = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Build my website.', 0);
            $planPages = $this->call('planPages', AIConversationMessage::STATUS_COMPLETED, 1);
            $planPages->inputTokens = 1500;
            $requestingCall = $this->call($requestingToolName, AIConversationMessage::STATUS_COMPLETED, 2);
            $requestingCall->inputTokens = 1700;
            $rows = [$seed, $planPages, $requestingCall];
            for ($position = 0; $position < $subAgentCount; $position++) {
                $rows[] = $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 3 + $position);
            }
            $withoutTheWaitRow = $this->messages(...$rows, ...[$this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3 + $subAgentCount)]);
            self::assertFalse($withoutTheWaitRow->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), 'the defect: a park without a turn end');

            $rows[] = $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 3 + $subAgentCount);
            $rows[] = $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 4 + $subAgentCount);
            $messages = $this->messages(...$rows);

            self::assertTrue($messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly(), $requestingToolName);
            self::assertTrue($messages->buildCurrentTurn()->readsSubAgentResults(), 'the notification turn\'s stall and checked-stop rules');
            self::assertFalse($messages->buildCurrentTurn()->hasModelCallSinceTheNewestTurnEnd(), 'the short wait runs at its head');
            self::assertTrue(AgentOfferedTools::buildFor($this->emptySkillCatalog(), $this->offerInputs(offersWaitForSubAgents: true))->offersTool(WaitForSubAgentsTool::NAME));
        }
    }

    /**
     * A resuming run end no model call read owes the parent a turn below the newest wait row
     * only while nothing ended a turn after that row — the park race. Once a turn ended with a text or an ERROR (a step
     * budget used up, a cost cap at step 0: both exit before the hand-over), the unread run ends never owe a turn again.
     */
    public function testAnUnreadRunEndIsOwedOnlyBelowTheNewestWaitRowWithNothingEndingATurnAfterIt(): void
    {
        $seed = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Build my website.', 0);
        $parkRace = $this->messages(
            $seed,
            $this->call('writeAllPendingPages', AIConversationMessage::STATUS_COMPLETED, 1),
            $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 2),
            $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 3),
            $this->unreadRunEnd(4),
            $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 5)
        );
        self::assertTrue($parkRace->hasUnansweredInboundMessage(), 'the park race: owed');
        self::assertFalse($parkRace->isNewestWireMessageFinalAssistantText());

        // the shape: run ends left unread, then the step budget's ERROR and the fallback text
        $budgetUsedUp = $this->messages(
            $seed,
            $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 1),
            $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 2),
            $this->unreadRunEnd(3),
            $this->unreadRunEnd(4),
            $this->errorRow('Turn step budget reached before completion', 5),
            $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'I could not finish this step.', 6)
        );
        self::assertFalse($budgetUsedUp->hasUnansweredInboundMessage(), 'a turn ended after them: never owed again');
        self::assertTrue($budgetUsedUp->isNewestWireMessageFinalAssistantText(), 'the recovery pass does not re-fire it');

        // a parked race whose next turn ended with an ERROR or a text before any hand-over is not owed either
        foreach ([$this->errorRow('Turn cost cap reached', 6), $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Done for now.', 6)] as $turnEnd) {
            $endedAfterThePark = $this->messages(
                $seed,
                $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 1),
                $this->unreadRunEnd(2),
                $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 3),
                $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Status?', 4),
                $turnEnd
            );
            self::assertFalse($endedAfterThePark->hasSubAgentRunEndWaitingForAModelCall());
        }
    }

    protected function unreadRunEnd(int $index): AIConversationMessage
    {
        $runEnd = $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, $index);
        $runEnd->status = AIConversationMessage::STATUS_PENDING;
        return $runEnd;
    }

    protected function errorRow(string $text, int $index): AIConversationMessage
    {
        $errorRow = $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $text, $index);
        $errorRow->messageType = AIConversationMessage::MESSAGE_ERROR;
        return $errorRow;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // the owed-work walks and the wait call
    // ---------------------------------------------------------------------------------------------------------------

    public function testBothOwedWorkWalksReadACompletedWaitCallAsTheTurnsEnd(): void
    {
        $waited = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 4)
        );
        self::assertFalse($waited->hasUnansweredInboundMessage());
        self::assertTrue($waited->isNewestWireMessageFinalAssistantText(), 'the lost-resume pass never re-fires it');

        $nextRunEnd = $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 5);
        $waited->add($nextRunEnd);
        self::assertTrue($waited->hasUnansweredInboundMessage(), 'the next run end owes a turn again');
        self::assertFalse($waited->isNewestWireMessageFinalAssistantText());

        $refused = $this->answeredOwnerTurnFollowedBy(
            $this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3),
            $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_FAILED, 4)
        );
        self::assertFalse($refused->isNewestWireMessageFinalAssistantText(), 'a refused call ended nothing');
    }

    public function testTheWaitToolAnswersAndEndsTheTurnOnlyWhenItCompleted(): void
    {
        $unfinished = new DelegationSubAgentRecords();
        foreach ([4, 5] as $number) {
            $unfinishedRecord = new DelegationSubAgentRecord();
            $unfinishedRecord->number = $number;
            $unfinished->add($unfinishedRecord);
        }

        $completed = WaitForSubAgentsTool::answerCall(true, true, $unfinished);
        self::assertFalse($completed->failed);
        self::assertSame('Waiting for sub-agents #4, #5 — each one\'s end arrives as a notification.', $completed->text);
        self::assertFalse($completed->forcesToolLessAnswerStep);

        $ownerTurn = WaitForSubAgentsTool::answerCall(false, true, $unfinished);
        self::assertTrue($ownerTurn->failed, 'an owner\'s turn is answered, never ended without a text');

        $nothingRuns = WaitForSubAgentsTool::answerCall(true, false, new DelegationSubAgentRecords());
        self::assertTrue($nothingRuns->failed);
        self::assertTrue($nothingRuns->forcesToolLessAnswerStep, 'no notification will come: the turn answers now');

        self::assertSame('Waiting for your sub-agents — each one\'s end arrives as a notification.', WaitForSubAgentsTool::buildWaitingText(new DelegationSubAgentRecords()));

        // the owner wrote while the turn ran — refused first, with their words, the next step without tools
        $ownerWrote = WaitForSubAgentsTool::answerCall(true, true, $unfinished, 'OWNER NOTE (message 9) "Are you there?"');
        self::assertTrue($ownerWrote->failed);
        self::assertTrue($ownerWrote->forcesToolLessAnswerStep);
        self::assertStringContainsString('Are you there?', $ownerWrote->text);

        $completedCall = $this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 0)->toolCall;
        self::assertTrue(AgentMetaTools::findAfterStepRequest($completedCall)?->endsTurnWithoutText());
        self::assertNull(AgentMetaTools::findAfterStepRequest($this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_FAILED, 0)->toolCall));
        self::assertSame(AgentAfterStepRequest::TYPE_END_TURN_WITHOUT_TEXT, AgentAfterStepRequest::endTurnWithoutText()->type);
        self::assertTrue($this->call(WaitForSubAgentsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 0)->isTurnEndWithoutTextCall());
        self::assertFalse($this->call(CheckDelegationsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 0)->isTurnEndWithoutTextCall());

        $schema = WaitForSubAgentsTool::create()->toOpenAiToolSchema();
        self::assertSame(WaitForSubAgentsTool::NAME, $schema['function']['name']);
        self::assertSame([], $schema['function']['parameters']['required'], 'no required argument');
        self::assertNotSame([], $schema['function']['parameters']['properties'], 'never an object schema without properties');
    }

    public function testTheWaitToolIsOfferedRightAfterTheStatusReadOnlyWhenTheInputsSaySo(): void
    {
        $offered = AgentOfferedTools::buildFor($this->emptySkillCatalog(), $this->offerInputs(offersWaitForSubAgents: true));
        $names = $offered->getToolNames();
        // the message to a sub-agent rides with the status read, the wait follows both
        $checkDelegationsPosition = (int)array_search(CheckDelegationsTool::NAME, $names, true);
        self::assertSame([CheckDelegationsTool::NAME, MessageSubAgentTool::NAME, WaitForSubAgentsTool::NAME], array_slice($names, $checkDelegationsPosition, 3));

        self::assertFalse(AgentOfferedTools::buildFor($this->emptySkillCatalog(), $this->offerInputs(offersWaitForSubAgents: false))->offersTool(WaitForSubAgentsTool::NAME));
        self::assertFalse(AgentOfferedTools::buildFor($this->emptySkillCatalog(), $this->offerInputs(offersWaitForSubAgents: true, allowThreads: false))->offersTool(WaitForSubAgentsTool::NAME), 'never to a sub-agent');
        self::assertFalse($offered->withoutTools(AgentMetaTools::NOT_WORK_FOR_THE_FORCED_STEP_NAMES)->offersTool(WaitForSubAgentsTool::NAME), 'never a work tool of a forced step');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // the stall text and the checked stop of such a turn
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheStallTextOfANotificationTurnOffersTheWaitOnlyWhenItIsOffered(): void
    {
        // 12-12e: the stall texts are the stall-recovery service's
        $agentStallRecoveryService = new AgentStallRecoveryService();
        $conversation = AIConversation::newInstance();
        $conversation->messages = $this->answeredOwnerTurnFollowedBy($this->signal(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, 3));
        $agentRun = \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun::forTurnEntry();

        $withWait = $agentStallRecoveryService->buildStallSignalText($agentRun, $conversation, true, 1, true);
        self::assertStringStartsWith('Sub-agent notifications started this turn and their results are in the notifications above', $withWait);
        self::assertStringContainsString('call wait_for_sub_agents to end the turn without a message', $withWait);

        $withoutWait = $agentStallRecoveryService->buildStallSignalText($agentRun, $conversation, true, 2, false);
        self::assertStringNotContainsString('wait_for_sub_agents', $withoutWait);
        self::assertStringEndsWith('(You have now produced no usable output 2× in a row.)', $withoutWait);

        $legacy = AIConversation::newInstance();
        $legacy->messages = $this->answeredOwnerTurnFollowedBy($this->signal(AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED, 3));
        self::assertStringStartsWith('Your delegated sub-agents have already finished', $agentStallRecoveryService->buildStallSignalText($agentRun, $legacy, true, 1, false));
    }

    public function testTheTurnClosingDecisionIsNotAskedOnATurnThatReadsSubAgentResults(): void
    {
        self::assertFalse(AgentCheckedStopPolicy::shouldAskTurnClosingDecision(new TaskPlan(), 0, 0, true, false, 'Here is what I found.'));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // the short wait — 2 s quiet / 8 s cap
    // ---------------------------------------------------------------------------------------------------------------

    public function testNoWaitWhenNoOtherSubAgentRuns(): void
    {
        $wait = AgentSubAgentRunEndQuietWait::startAt(1000, 1);
        self::assertFalse($wait->continuesAfterPoll(1000, 1, false));
        self::assertSame(AgentSubAgentRunEndQuietWait::ENDED_NO_UNFINISHED_RUN, $wait->endReason);
        self::assertSame(0, $wait->getWaitedMilliseconds());
    }

    public function testTwoSecondsOfQuietEndTheWaitAndANewRunEndRestartsTheQuiet(): void
    {
        $wait = AgentSubAgentRunEndQuietWait::startAt(0, 1);
        self::assertTrue($wait->continuesAfterPoll(0, 1, true));
        self::assertTrue($wait->continuesAfterPoll(1500, 2, true), 'a second run end at 1.5 s');
        self::assertTrue($wait->continuesAfterPoll(3250, 2, true), 'quiet since 1.5 s: 1.75 s');
        self::assertFalse($wait->continuesAfterPoll(3500, 2, true));
        self::assertSame(AgentSubAgentRunEndQuietWait::ENDED_QUIET, $wait->endReason);
        self::assertSame(1, $wait->runEndsArrivedDuringWait);
        self::assertTrue($wait->hasRunEndsArrivedDuringWait());
        self::assertFalse($wait->continuesAfterPoll(3750, 3, true), 'an ended wait stays ended');
    }

    public function testEightSecondsCapTheWaitWhateverArrives(): void
    {
        $wait = AgentSubAgentRunEndQuietWait::startAt(0, 1);
        $waitingCount = 1;
        $pollAt = 0;
        while ($wait->continuesAfterPoll($pollAt, $waitingCount, true)) {
            $pollAt += 250;
            $waitingCount += $pollAt % 1500 === 0 ? 1 : 0; // a run end every 1.5 s keeps the quiet from ending it
        }
        self::assertSame(AgentSubAgentRunEndQuietWait::ENDED_CAP, $wait->endReason);
        self::assertSame(AgentSubAgentRunEndQuietWait::CAP_MILLISECONDS, $wait->getWaitedMilliseconds());
    }

    public function testTheLastRunEndingDuringTheWaitEndsItAtOnce(): void
    {
        $wait = AgentSubAgentRunEndQuietWait::startAt(0, 1);
        self::assertTrue($wait->continuesAfterPoll(0, 1, true));
        self::assertFalse($wait->continuesAfterPoll(250, 2, false));
        self::assertSame(AgentSubAgentRunEndQuietWait::ENDED_NO_UNFINISHED_RUN, $wait->endReason);
        self::assertSame(1, $wait->runEndsArrivedDuringWait);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // the loop's stop and the release
    // ---------------------------------------------------------------------------------------------------------------

    public function testATurnEndedWaitingNeitherReportsNorYieldsAndReleasesRunningWithItsOwnOutcome(): void
    {
        $loopStopReason = AgentLoopStopReason::waitingForSubAgents();
        self::assertTrue($loopStopReason->isWaitingForSubAgents());
        self::assertFalse($loopStopReason->isAnswered());
        self::assertFalse($loopStopReason->endsWithATerminalReport(), 'no terminal ERROR row, no run report');
        self::assertFalse($loopStopReason->mayYieldForContinuation());

        $runRelease = AgentRunRelease::afterWaitingForSubAgents(7);
        self::assertSame(AIConversationTurnEvent::OUTCOME_WAITING_FOR_SUB_AGENTS, $runRelease->turnEndOutcome);
        self::assertSame(AIConversationRunState::IDLE, $runRelease->runState);
        self::assertSame(0, $runRelease->resumeAttempts);
        self::assertSame(AIConversationRealtimeSignalService::STATUS_RUNNING, $runRelease->realtimeStatus, 'the sub-agents work');
        self::assertSame(AgentRunRelease::WAITING_SIGNAL_PROBE_AFTER_PUBLISH, $runRelease->waitingSignalProbe);
        self::assertSame(7, $runRelease->waitingSignalProbeAboveIndex);
        self::assertFalse($runRelease->runsSettledTurnFollowUps, 'no memory curation, no supervisor beat, no purpose close');
        self::assertFalse($runRelease->reFiresAfterRelease);
        self::assertTrue($runRelease->promotesQueuedDelegations);

        $ownerWroteRelease = AgentRunRelease::afterWaitingForSubAgents(7, true);
        self::assertTrue($ownerWroteRelease->reFiresAfterRelease, 'owner input that arrived during the turn re-fires at once (verifier B1)');
        self::assertSame(AgentRunRelease::WAITING_SIGNAL_PROBE_NONE, $ownerWroteRelease->waitingSignalProbe);
        self::assertSame(AIConversationTurnEvent::OUTCOME_WAITING_FOR_SUB_AGENTS, $ownerWroteRelease->turnEndOutcome);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------------------------

    /** The owner asked, the agent delegated and answered — then `$rowsAfterTheAnswer` (indexes from 3 on). */
    protected function answeredOwnerTurnFollowedBy(AIConversationMessage ...$rowsAfterTheAnswer): AIConversationMessages
    {
        $answer = $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Two sub-agents run; I report when they end.', 2);
        $answer->inputTokens = 1200;
        $delegateCall = $this->call('delegate', AIConversationMessage::STATUS_COMPLETED, 1);
        $delegateCall->inputTokens = 1000;
        return $this->messages(
            $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Check my three competitors.', 0),
            $delegateCall,
            $answer,
            ...$rowsAfterTheAnswer
        );
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
        $message = $this->text(AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, '[SIGNAL] ' . $signalSource, $index);
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->signalSource = $signalSource;
        return $message;
    }

    protected function call(string $toolName, string $status, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $message->indexInConversation = $index;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = $status;
        $toolCall->arguments = [];
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

    protected function emptySkillCatalog(): OfferedSkillCatalog
    {
        /** @var OfferedSkillCatalog $offeredSkillCatalog */
        $offeredSkillCatalog = self::instantiateWithoutConstructor(OfferedSkillCatalog::class);
        $offeredSkillCatalog->skills = new AISkills();
        $offeredSkillCatalog->alreadyLoadedSkillNames = [];
        return $offeredSkillCatalog;
    }

    protected function offerInputs(bool $offersWaitForSubAgents, bool $allowThreads = true): AgentOfferedToolsInputs
    {
        $agentOfferedToolsInputs = new AgentOfferedToolsInputs();
        $agentOfferedToolsInputs->allowThreads = $allowThreads;
        $agentOfferedToolsInputs->hasDelegateCall = true;
        $agentOfferedToolsInputs->offersWaitForSubAgents = $offersWaitForSubAgents;
        $agentOfferedToolsInputs->loadedSkillToolDefinitions = new ToolDefinitions();
        return $agentOfferedToolsInputs;
    }
}
