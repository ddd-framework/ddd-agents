<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * Who drives a conversation's run, against the database, on both engines: the claim and its release, the heartbeat,
 * the guarded run-state transitions, the re-arms, the admission of queued delegation children under the tree's named
 * lock, the reads of unfinished sub-agent runs and of their live activity, and the cancel of a live run.
 *
 * The statements of {@see AIConversationRunClaimsService} decide by affected rows; every test that reads such a count
 * therefore runs the same assertions on MariaDB (changed rows) and on PostgreSQL (matched rows).
 */
class AIConversationRunClaimsDatabaseTest extends DatabaseTestCase
{
    protected const int ROOT_CONVERSATION_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
    }

    protected function onTearDown(): void
    {
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
    }

    public function testAClaimOfAnIdleConversationWinsOnceAndStampsTheRunColumns(): void
    {
        $this->insertConversation([
            'id' => self::ROOT_CONVERSATION_ID,
            'runState' => AIConversationRunState::IDLE,
            'agentTurnOwedAt' => $this->formatMomentSecondsAgo(60),
            'agentWorkingState' => '{"taskPlan":{"open":true}}',
        ]);
        $conversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);

        self::assertTrue($this->getRunClaimsService()->claimRun($conversation, 'token-of-the-winner'));

        $storedConversation = $this->findStoredConversation(self::ROOT_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::RUNNING, $storedConversation['runState']);
        self::assertSame('token-of-the-winner', $storedConversation['runToken']);
        self::assertNotNull($storedConversation['runHeartbeatAt']);
        self::assertNull($storedConversation['agentTurnOwedAt']);
        $storedWorkingState = json_decode((string)$storedConversation['agentWorkingState'], true);
        self::assertSame(AIConversationsService::currentEnvironment(), $storedWorkingState['environment']);
        self::assertSame(['open' => true], $storedWorkingState['taskPlan'], 'the other slices of the working state stay');
        self::assertSame(AIConversationRunState::RUNNING, $conversation->runState);
        self::assertSame('token-of-the-winner', $this->getRunClaimsService()->getRunTokenClaimedInThisProcess($conversation));

        self::assertFalse(
            $this->getRunClaimsService()->claimRun($this->buildConversation(self::ROOT_CONVERSATION_ID), 'token-of-the-loser'),
            'a second claim of a row with a live heartbeat loses'
        );
        self::assertSame('token-of-the-winner', $this->findStoredConversation(self::ROOT_CONVERSATION_ID)['runToken']);
    }

    public function testAClaimWritesTheEnvironmentIntoAConversationWithoutAWorkingState(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);

        self::assertTrue($this->getRunClaimsService()->claimRun($this->buildConversation(self::ROOT_CONVERSATION_ID), 'token'));

        $storedWorkingState = json_decode((string)$this->findStoredConversation(self::ROOT_CONVERSATION_ID)['agentWorkingState'], true);
        self::assertSame(['environment' => AIConversationsService::currentEnvironment()], $storedWorkingState);
    }

    public function testAClaimTakesARunningRowWithAHungHeartbeatAndNeverAnEndedOrQueuedRow(): void
    {
        $this->insertConversation([
            'id' => 101,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => 'token-of-the-crashed-worker',
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS + 60),
        ]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::COMPLETED]);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::FAILED]);
        $this->insertConversation(['id' => 104, 'runState' => AIConversationRunState::CANCELLED]);
        $this->insertConversation(['id' => 105, 'runState' => AIConversationRunState::QUEUED]);

        self::assertTrue($this->getRunClaimsService()->claimRun($this->buildConversation(101), 'token-of-the-new-worker'));
        self::assertSame('token-of-the-new-worker', $this->findStoredConversation(101)['runToken']);
        foreach ([102, 103, 104, 105] as $conversationIdThatIsNeverClaimed) {
            self::assertFalse($this->getRunClaimsService()->claimRun($this->buildConversation($conversationIdThatIsNeverClaimed), 'token'));
            self::assertNull($this->findStoredConversation($conversationIdThatIsNeverClaimed)['runToken']);
        }
    }

    public function testAHeartbeatInTheSecondOfTheClaimStillHoldsTheClaimAndALostClaimDoesNot(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
        $conversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);
        $runClaimsService = $this->getRunClaimsService();
        self::assertTrue($runClaimsService->claimRun($conversation, 'token-of-this-worker'));

        self::assertTrue($runClaimsService->heartbeat($conversation, 'token-of-this-worker'), 'the same second writes the same value');
        self::assertTrue($runClaimsService->holdsRunClaim($conversation, 'token-of-this-worker'));
        self::assertTrue($runClaimsService->hasLiveRunClaim($conversation));
        self::assertSame(AIConversationRunState::RUNNING, $runClaimsService->findRunStateById(self::ROOT_CONVERSATION_ID));

        $this->writeConversationColumns(self::ROOT_CONVERSATION_ID, ['runToken' => 'token-of-another-worker']);

        self::assertFalse($runClaimsService->heartbeat($conversation, 'token-of-this-worker'));
        self::assertFalse($runClaimsService->holdsRunClaim($conversation, 'token-of-this-worker'));
        self::assertTrue($runClaimsService->hasLiveRunClaim($conversation), 'another worker holds it');
        self::assertNull($runClaimsService->findRunStateById(999));
    }

    public function testTheHeartbeatWritesOutsideAClaimMoveTheHeartbeatOfTheRowsTheyAreMeantFor(): void
    {
        $hungHeartbeat = $this->formatMomentSecondsAgo(900);
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'runHeartbeatAt' => $hungHeartbeat]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'runHeartbeatAt' => $hungHeartbeat]);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::AWAITING_DELEGATES, 'runHeartbeatAt' => $hungHeartbeat]);
        $runClaimsService = $this->getRunClaimsService();

        $runClaimsService->touchRunningHeartbeat(101);
        $runClaimsService->touchRunningHeartbeat(102);
        $awaitingConversation = $this->buildConversation(103);
        $runClaimsService->markRecoveryDispatched($awaitingConversation);

        self::assertNotSame($hungHeartbeat, $this->findStoredConversation(101)['runHeartbeatAt'], 'a running row is touched');
        self::assertSame($hungHeartbeat, $this->findStoredConversation(102)['runHeartbeatAt'], 'a row that does not run is not');
        self::assertNotSame($hungHeartbeat, $this->findStoredConversation(103)['runHeartbeatAt']);
        self::assertTrue($runClaimsService->hasLiveRunClaim($this->buildConversation(101)));
        self::assertFalse($runClaimsService->hasLiveRunClaim($this->buildConversation(103)), 'awaiting is no live claim');
    }

    public function testAReleaseWritesTheStateTheAttemptsAndTheOwedMarkerOnlyForTheHolderOfTheToken(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
        $conversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);
        $runClaimsService = $this->getRunClaimsService();
        self::assertTrue($runClaimsService->claimRun($conversation, 'token-of-this-worker'));
        $owedAt = DateTime::fromString('2026-03-04 05:06:07');

        self::assertFalse($runClaimsService->releaseRun($this->buildConversation(self::ROOT_CONVERSATION_ID), 'token-of-nobody', AIConversationRunState::IDLE));
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(self::ROOT_CONVERSATION_ID)['runState']);

        self::assertTrue($runClaimsService->releaseRun($conversation, 'token-of-this-worker', AIConversationRunState::IDLE, 2, $owedAt));

        $storedConversation = $this->findStoredConversation(self::ROOT_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::IDLE, $storedConversation['runState']);
        self::assertNull($storedConversation['runToken']);
        self::assertSame(2, (int)$storedConversation['resumeAttempts']);
        self::assertSame('2026-03-04 05:06:07', $storedConversation['agentTurnOwedAt']);
        self::assertNull($runClaimsService->getRunTokenClaimedInThisProcess($conversation));
        self::assertFalse($runClaimsService->releaseRun($conversation, 'token-of-this-worker', AIConversationRunState::IDLE), 'a second release finds no token');
    }

    public function testATransitionAndACloseWinOnlyFromTheStateTheyAreGuardedOn(): void
    {
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::QUEUED]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'runToken' => 'left-over-token']);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-of-a-worker']);
        $runClaimsService = $this->getRunClaimsService();

        self::assertTrue($runClaimsService->transitionRunState($this->buildConversation(101), AIConversationRunState::QUEUED, AIConversationRunState::IDLE));
        self::assertFalse($runClaimsService->transitionRunState($this->buildConversation(101), AIConversationRunState::QUEUED, AIConversationRunState::IDLE));
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredConversation(101)['runState']);

        self::assertTrue($runClaimsService->closeConversation($this->buildConversation(102)));
        self::assertFalse($runClaimsService->closeConversation($this->buildConversation(102)), 'a closed conversation is not closed again');
        self::assertFalse($runClaimsService->closeConversation($this->buildConversation(103)), 'a running conversation does not close');
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(102)['runState']);
        self::assertNull($this->findStoredConversation(102)['runToken']);
        self::assertSame('token-of-a-worker', $this->findStoredConversation(103)['runToken']);
    }

    public function testARetryReArmsAFailedRootWithAResetCounterAndAnIdleRootWithItsCounter(): void
    {
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::FAILED, 'resumeAttempts' => 3, 'runToken' => 'left-over-token']);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'resumeAttempts' => 2]);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::RUNNING, 'resumeAttempts' => 1, 'runToken' => 'token-of-a-worker']);
        $runClaimsService = $this->getRunClaimsService();
        $failedRoot = $this->buildConversation(101);
        $idleRoot = $this->buildConversation(102);

        self::assertTrue($runClaimsService->rearmRunForRetry($failedRoot));
        self::assertTrue($runClaimsService->rearmRunForRetry($idleRoot));
        self::assertTrue($runClaimsService->rearmRunForRetry($idleRoot), 'a second click within the same second is no refusal');
        self::assertFalse($runClaimsService->rearmRunForRetry($this->buildConversation(103)), 'a running conversation is not re-armed');

        $storedFailedRoot = $this->findStoredConversation(101);
        self::assertSame(AIConversationRunState::IDLE, $storedFailedRoot['runState']);
        self::assertSame(0, (int)$storedFailedRoot['resumeAttempts']);
        self::assertNull($storedFailedRoot['runToken']);
        self::assertNotNull($storedFailedRoot['agentTurnOwedAt']);
        self::assertSame(0, $failedRoot->resumeAttempts);
        $storedIdleRoot = $this->findStoredConversation(102);
        self::assertSame(2, (int)$storedIdleRoot['resumeAttempts'], 'the counter of a turn that errored under the cap stays');
        self::assertNotNull($storedIdleRoot['agentTurnOwedAt']);
        self::assertSame(2, $idleRoot->resumeAttempts);
        self::assertSame('token-of-a-worker', $this->findStoredConversation(103)['runToken']);
    }

    public function testARetryNeverReArmsADelegationChildAndAnEndedChildIsReQueuedOnItsOwnPath(): void
    {
        $this->insertParentWithDelegateCall(501, AIConversationMessage::STATUS_COMPLETED);
        $this->insertDeadline(301);
        $this->insertDeadline(302);
        $this->insertConversation([
            'id' => 201,
            'rootConversationId' => self::ROOT_CONVERSATION_ID,
            'delegatedFromMessageId' => 501,
            'delegationBatchId' => 301,
            'runState' => AIConversationRunState::FAILED,
            'resumeAttempts' => 3,
            'runToken' => 'left-over-token',
            'agentTurnOwedAt' => $this->formatMomentSecondsAgo(30),
        ]);
        $runClaimsService = $this->getRunClaimsService();
        $endedChild = $this->buildConversation(201);
        $endedChild->delegatedFromMessageId = 501;
        $continuationRunDeadline = new AIConversationDelegationBatch();
        $continuationRunDeadline->id = 302;

        self::assertFalse($runClaimsService->rearmRunForRetry($endedChild));
        self::assertFalse($runClaimsService->rearmTerminalDelegationChild($this->buildConversation(self::ROOT_CONVERSATION_ID)), 'a root is no child');
        self::assertTrue($runClaimsService->rearmTerminalDelegationChild($endedChild, $continuationRunDeadline));
        self::assertFalse($runClaimsService->rearmTerminalDelegationChild($endedChild, $continuationRunDeadline), 'a queued child is not re-armed again');

        $storedChild = $this->findStoredConversation(201);
        self::assertSame(AIConversationRunState::QUEUED, $storedChild['runState']);
        self::assertNull($storedChild['runToken']);
        self::assertNull($storedChild['agentTurnOwedAt']);
        self::assertSame(0, (int)$storedChild['resumeAttempts']);
        self::assertSame(302, (int)$storedChild['delegationBatchId']);
        self::assertSame(501, $runClaimsService->findDelegatedFromMessageIdById(201));
        self::assertNull($runClaimsService->findDelegatedFromMessageIdById(self::ROOT_CONVERSATION_ID));
    }

    public function testQueuedChildrenAreAdmittedOldestFirstUpToTheFreeSlotsWithAFullDeadlineWindow(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::AWAITING_DELEGATES]);
        $deadlineAlmostReached = $this->formatMomentSecondsAgo(-10);
        $queuedChildIds = [201, 202, 203, 204, 205, 206, 207];
        foreach ($queuedChildIds as $queuedChildId) {
            $this->insertDeadline($queuedChildId + 100, ['timeoutSeconds' => 300, 'deadlineAt' => $deadlineAlmostReached]);
            $this->insertConversation([
                'id' => $queuedChildId,
                'rootConversationId' => self::ROOT_CONVERSATION_ID,
                'delegationBatchId' => $queuedChildId + 100,
                'runState' => AIConversationRunState::QUEUED,
            ]);
        }
        $runClaimsService = $this->getRunClaimsService();
        $earliestFullWindowEnd = (new DateTime())->modify('+295 seconds')->format('Y-m-d H:i:s');

        $admittedChildIds = $runClaimsService->admitQueuedDelegationChildren(self::ROOT_CONVERSATION_ID);

        self::assertSame([201, 202, 203, 204, 205], $admittedChildIds);
        self::assertSame(AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP, $runClaimsService->countOccupiedDelegationSlotsUnderRoot(self::ROOT_CONVERSATION_ID));
        foreach ($admittedChildIds as $admittedChildId) {
            $storedChild = $this->findStoredConversation($admittedChildId);
            self::assertSame(AIConversationRunState::IDLE, $storedChild['runState']);
            self::assertNotNull($storedChild['agentTurnOwedAt'], 'the admission stamp is the reservation');
            $storedDeadline = $this->findStoredRow(DBAIConversationDelegationBatchModel::getTableName(), $admittedChildId + 100);
            self::assertGreaterThanOrEqual($earliestFullWindowEnd, $storedDeadline['deadlineAt'], 'an admitted child gets a full window from now');
        }
        self::assertSame(AIConversationRunState::QUEUED, $this->findStoredConversation(206)['runState']);
        self::assertSame($deadlineAlmostReached, $this->findStoredRow(DBAIConversationDelegationBatchModel::getTableName(), 306)['deadlineAt']);
        self::assertSame([], $runClaimsService->admitQueuedDelegationChildren(self::ROOT_CONVERSATION_ID), 'every slot is occupied');

        $this->writeConversationColumns(201, ['runState' => AIConversationRunState::COMPLETED, 'agentTurnOwedAt' => null]);
        self::assertSame([206], $runClaimsService->admitQueuedDelegationChildren(self::ROOT_CONVERSATION_ID), 'the lock is free again and one slot is');
    }

    public function testAnAwaitedConversationIsStampedAsAChildOnlyWhileItBelongsToNoDelegateRowAndHasNotEnded(): void
    {
        $this->insertParentWithDelegateCall(501, AIConversationMessage::STATUS_RUNNING);
        $this->insertDeadline(301);
        $this->insertConversation(['id' => 201, 'runState' => AIConversationRunState::RUNNING]);
        $this->insertConversation(['id' => 202, 'runState' => AIConversationRunState::COMPLETED]);
        $runClaimsService = $this->getRunClaimsService();

        self::assertTrue($runClaimsService->stampAwaitedConversationAsSubAgentChild(201, 501, 301, self::ROOT_CONVERSATION_ID));
        self::assertFalse($runClaimsService->stampAwaitedConversationAsSubAgentChild(201, 501, 301, self::ROOT_CONVERSATION_ID), 'it belongs to a delegate row now');
        self::assertFalse($runClaimsService->stampAwaitedConversationAsSubAgentChild(202, 501, 301, self::ROOT_CONVERSATION_ID), 'an ended conversation');
        self::assertFalse(
            $runClaimsService->stampAwaitedConversationAsSubAgentChild(self::ROOT_CONVERSATION_ID, 501, 301, self::ROOT_CONVERSATION_ID),
            'the root is never its own child'
        );

        $storedChild = $this->findStoredConversation(201);
        self::assertSame(501, (int)$storedChild['delegatedFromMessageId']);
        self::assertSame(301, (int)$storedChild['delegationBatchId']);
        self::assertSame(self::ROOT_CONVERSATION_ID, (int)$storedChild['rootConversationId']);
    }

    public function testTheNewestTurnEndEventOfTheMessageWindowDecidesWhetherTheTurnEndedFailed(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID]);
        $this->insertTurnEndEvent(1, 4, AIConversationTurnEvent::OUTCOME_FAILED);
        $this->insertTurnEndEvent(2, 4, AIConversationTurnEvent::OUTCOME_ANSWERED);
        $this->insertTurnEndEvent(3, 9, AIConversationTurnEvent::OUTCOME_FAILED);
        $conversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);
        $runClaimsService = $this->getRunClaimsService();

        self::assertFalse($runClaimsService->hasTurnEndedFailedBetweenMessageIndexes($conversation, 4, 9), 'the later retry answered');
        self::assertTrue($runClaimsService->hasTurnEndedFailedBetweenMessageIndexes($conversation, 4, null));
        self::assertTrue($runClaimsService->hasTurnEndedFailedBetweenMessageIndexes($conversation, 9, 12));
        self::assertFalse($runClaimsService->hasTurnEndedFailedBetweenMessageIndexes($conversation, 10, null), 'no turn end in the window');
    }

    public function testTheUnfinishedSubAgentRunsOfAParentAreTheChildrenWhoseRunHasNotEnded(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::AWAITING_DELEGATES]);
        $this->insertDelegateCall(501, 1, AIConversationMessage::STATUS_RUNNING, ['subAgent' => ['number' => 1]]);
        $this->insertDelegateCall(502, 2, AIConversationMessage::STATUS_COMPLETED, null);
        $this->insertDelegateCall(503, 3, AIConversationMessage::STATUS_RUNNING, null);
        $this->insertDelegateCall(504, 4, AIConversationMessage::STATUS_COMPLETED, ['subAgent' => ['number' => 4]]);
        $this->insertDeadline(301);
        $this->insertChild(201, 501, 301, AIConversationRunState::RUNNING);
        $this->insertChild(202, 502, null, AIConversationRunState::IDLE);
        $this->insertChild(203, 503, null, AIConversationRunState::IDLE);
        $this->insertChild(204, 504, null, AIConversationRunState::COMPLETED);
        $parentConversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);
        $runClaimsService = $this->getRunClaimsService();

        self::assertSame(
            [201, 203],
            $runClaimsService->findUnfinishedSubAgentChildIdsOfParent($parentConversation),
            'an idle child without a deadline row whose delegate call ended has ended; an ended child has'
        );
        self::assertTrue($runClaimsService->hasUnfinishedSubAgentRunOfParent($parentConversation));
        self::assertTrue($runClaimsService->hasUnfinishedSubAgentRunThatResumesParent($parentConversation));
        self::assertFalse($runClaimsService->hasUnfinishedSubAgentRunOfParent($this->buildConversation(201)), 'a child has no children');
    }

    public function testAnUnfinishedRunThatNotifiesWithoutResumingDoesNotCountAsOneThatResumesTheParent(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
        $this->insertDelegateCall(501, 1, AIConversationMessage::STATUS_RUNNING, ['subAgent' => ['number' => 1, 'notifiesParentWithoutResuming' => true]]);
        $this->insertDelegateCall(502, 2, AIConversationMessage::STATUS_RUNNING, ['subAgent' => ['number' => 2]]);
        $this->insertDeadline(301);
        $this->insertDeadline(302, ['delegateWakeCondition' => AIConversationDelegationResumeCondition::BACKGROUND]);
        $this->insertChild(201, 501, 301, AIConversationRunState::RUNNING);
        $this->insertChild(202, 502, 302, AIConversationRunState::RUNNING);
        $parentConversation = $this->buildConversation(self::ROOT_CONVERSATION_ID);
        $runClaimsService = $this->getRunClaimsService();

        self::assertSame([201, 202], $runClaimsService->findUnfinishedSubAgentChildIdsOfParent($parentConversation));
        self::assertFalse(
            $runClaimsService->hasUnfinishedSubAgentRunThatResumesParent($parentConversation),
            'one notifies without resuming by its record, the other by its background deadline row'
        );

        $this->insertDelegateCall(503, 3, AIConversationMessage::STATUS_RUNNING, ['subAgent' => ['number' => 3, 'notifiesParentWithoutResuming' => false]]);
        $this->insertDeadline(303);
        $this->insertChild(203, 503, 303, AIConversationRunState::QUEUED);

        self::assertTrue($runClaimsService->hasUnfinishedSubAgentRunThatResumesParent($parentConversation));
    }

    public function testTheLiveActivityOfARunningSubAgentNamesItsNewestStepsAndTheirCount(): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::AWAITING_DELEGATES]);
        $heartbeat = $this->formatMomentSecondsAgo(5);
        $this->insertConversation(['id' => 201, 'rootConversationId' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::RUNNING, 'runHeartbeatAt' => $heartbeat]);
        $this->insertConversation(['id' => 202, 'rootConversationId' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::QUEUED]);
        $runStart = $this->formatMomentSecondsAgo(120);
        $this->insertToolCallStep(601, 201, 1, 'read_before_the_run', AIConversationMessage::STATUS_COMPLETED, $this->formatMomentSecondsAgo(600));
        foreach ([2, 3, 4, 5, 6, 7, 8] as $stepIndex) {
            $this->insertToolCallStep(600 + $stepIndex, 201, $stepIndex, 'read_number_' . $stepIndex, AIConversationMessage::STATUS_COMPLETED, $this->formatMomentSecondsAgo(100 - $stepIndex));
        }
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        foreach ([1 => 201, 2 => 202] as $number => $childConversationId) {
            $delegationSubAgentRecord = DelegationSubAgentRecord::fromStoredArray([
                'number' => $number,
                'childConversationId' => $childConversationId,
                'currentRunNumber' => 1,
                'runs' => [['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_RUNNING, 'queuedAt' => $runStart]],
            ]);
            $delegationSubAgentRecords->add($delegationSubAgentRecord);
        }

        $agentSubAgentLiveActivities = $this->getRunClaimsService()->findSubAgentLiveActivities($delegationSubAgentRecords);

        $liveActivities = $agentSubAgentLiveActivities->getElements();
        self::assertCount(2, $liveActivities);
        self::assertSame(201, $liveActivities[0]->childConversationId);
        self::assertSame(AIConversationRunState::RUNNING, $liveActivities[0]->runState);
        self::assertSame($heartbeat, $liveActivities[0]->runHeartbeatAt?->format('Y-m-d H:i:s'));
        self::assertSame(7, $liveActivities[0]->stepCount, 'the step before the run started is not counted');
        self::assertCount(AIConversationRunClaimsService::SUB_AGENT_LIVE_STEPS_LIMIT, $liveActivities[0]->newestSteps);
        self::assertSame('read_number_8', $liveActivities[0]->newestSteps[0]->toolName);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $liveActivities[0]->newestSteps[0]->status);
        self::assertNotNull($liveActivities[0]->newestSteps[0]->createdAt);
        self::assertSame('read_number_4', $liveActivities[0]->newestSteps[4]->toolName);
        self::assertSame(202, $liveActivities[1]->childConversationId);
        self::assertSame(0, $liveActivities[1]->stepCount);
        self::assertSame([], $liveActivities[1]->newestSteps);
    }

    public function testACancelEndsALiveRunOnceAndLeavesAnEndedConversationAsItIs(): void
    {
        $recordingRealtimeSignalService = new RecordingRealtimeSignalService();
        $this->replaceService(AIConversationRealtimeSignalService::class, $recordingRealtimeSignalService);
        $this->insertConversation([
            'id' => 101,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => 'token-of-a-worker',
            'agentTurnOwedAt' => $this->formatMomentSecondsAgo(30),
        ]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::COMPLETED]);
        $runClaimsService = $this->getRunClaimsService();
        $runningConversation = $this->buildConversation(101);

        self::assertTrue($runClaimsService->cancelLiveRun($runningConversation));
        self::assertFalse($runClaimsService->cancelLiveRun($runningConversation), 'a cancelled run is not cancelled again');
        self::assertFalse($runClaimsService->cancelLiveRun($this->buildConversation(102)));

        $storedConversation = $this->findStoredConversation(101);
        self::assertSame(AIConversationRunState::CANCELLED, $storedConversation['runState']);
        self::assertNull($storedConversation['runToken']);
        self::assertNull($storedConversation['agentTurnOwedAt']);
        self::assertSame(AIConversationRunState::CANCELLED, $runningConversation->runState);
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(102)['runState']);
        self::assertSame([AIConversationRealtimeSignalService::STATUS_CANCELLED], $recordingRealtimeSignalService->publishedRunStatuses);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertFalse($runClaimsService->claimRun($runningConversation, 'token-after-the-cancel'), 'no claim takes a cancelled row');
    }

    protected function getRunClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** A conversation object that names the stored row `$conversationId` and nothing else. */
    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** The moment `$seconds` before now (a negative number: after now) as the database stores it. */
    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify(($seconds >= 0 ? '-' : '+') . abs($seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversation(array $columnValues): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues);
    }

    /** @return array<string, mixed> */
    protected function findStoredConversation(int $conversationId): array
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), $conversationId);
        self::assertNotNull($storedConversation, 'conversation ' . $conversationId . ' is stored');
        return $storedConversation;
    }

    /**
     * Writes columns of a stored conversation the way another process would have.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function writeConversationColumns(int $conversationId, array $columnValues): void
    {
        $assignments = [];
        foreach (array_keys($columnValues) as $columnName) {
            $assignments[] = '`' . $columnName . '` = ?';
        }
        $this->getDatabaseConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET ' . implode(', ', $assignments) . ' WHERE `id` = ?',
            [...array_values($columnValues), $conversationId]
        );
    }

    /** The root conversation and one `delegate` call row of it. */
    protected function insertParentWithDelegateCall(int $delegateCallMessageId, string $delegateCallStatus): void
    {
        $this->insertConversation(['id' => self::ROOT_CONVERSATION_ID, 'runState' => AIConversationRunState::AWAITING_DELEGATES]);
        $this->insertDelegateCall($delegateCallMessageId, 1, $delegateCallStatus, null);
    }

    /**
     * @param array<string, mixed>|null $delegationResults The stored sub-agent record of the row, null for a row without one
     */
    protected function insertDelegateCall(int $messageId, int $indexInConversation, string $delegateCallStatus, ?array $delegationResults): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::ROOT_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode(['name' => 'delegate', 'status' => $delegateCallStatus]),
            'delegationResults' => $delegationResults === null ? null : json_encode($delegationResults),
        ]);
    }

    protected function insertToolCallStep(int $messageId, int $conversationId, int $indexInConversation, string $toolName, string $toolCallStatus, string $created): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode(['name' => $toolName, 'status' => $toolCallStatus]),
            'created' => $created,
        ]);
    }

    /**
     * The deadline row of one sub-agent run of the root conversation.
     *
     * @param array<string, mixed> $columnValues Columns that differ from a plain row
     */
    protected function insertDeadline(int $deadlineId, array $columnValues = []): void
    {
        $this->insertRow(DBAIConversationDelegationBatchModel::getTableName(), $columnValues + [
            'id' => $deadlineId,
            'parentConversationId' => self::ROOT_CONVERSATION_ID,
            'expectedDelegationCount' => 1,
        ]);
    }

    protected function insertChild(int $childConversationId, int $delegateCallMessageId, ?int $deadlineId, string $runState): void
    {
        $this->insertConversation([
            'id' => $childConversationId,
            'rootConversationId' => self::ROOT_CONVERSATION_ID,
            'delegatedFromMessageId' => $delegateCallMessageId,
            'delegationBatchId' => $deadlineId,
            'runState' => $runState,
        ]);
    }

    protected function insertTurnEndEvent(int $turnEventId, int $initiatorMessageIndex, string $outcome): void
    {
        $this->insertRow(DBAIConversationTurnEventModel::getTableName(), [
            'id' => $turnEventId,
            'aiConversationId' => self::ROOT_CONVERSATION_ID,
            'initiatorMessageIndex' => $initiatorMessageIndex,
            'kind' => AIConversationTurnEvent::KIND_TURN_END,
            'eventKey' => 'turn-end-' . $turnEventId,
            'payload' => json_encode(['outcome' => $outcome]),
        ]);
    }
}
