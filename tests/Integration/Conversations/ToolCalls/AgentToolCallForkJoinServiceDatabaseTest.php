<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\ToolCalls;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Symfony\Component\Process\Process;

/**
 * The raw read of {@see AgentToolCallForkJoinService} against the database, on both engines: the parent reads the
 * outcome a child wrote into its call row — a plain read outside a transaction, a locking read for share inside one —
 * and takes it only under the child's lease.
 */
class AgentToolCallForkJoinServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected const int CALL_MESSAGE_ID = 701;

    protected const string LEASE = 'lease-of-the-child';

    protected AgentToolCallForkJoinService $forkJoinService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->forkJoinService = new AgentToolCallForkJoinService();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::RUNNING]);
    }

    protected function onTearDown(): void
    {
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheOutcomeTheChildWroteUnderItsLeaseIsTakenOutsideAndInsideATransaction(): void
    {
        $this->insertCallRow(['toolCallId' => 'call-1', 'name' => 'findReviews', 'status' => AIConversationMessage::STATUS_COMPLETED, 'executionLease' => self::LEASE, 'result' => 'three reviews']);

        $outcomeOutsideATransaction = $this->forkJoinService->waitForOutcome($this->buildForkWithExitedChild(0), 'call-1', $this->buildConversation());
        self::assertInstanceOf(ToolCall::class, $outcomeOutsideATransaction);
        self::assertSame('three reviews', $outcomeOutsideATransaction->result);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $outcomeOutsideATransaction->status);

        $connection = $this->getDatabaseConnection();
        $connection->beginTransaction();
        try {
            $outcomeInsideATransaction = $this->forkJoinService->waitForOutcome($this->buildForkWithExitedChild(0), 'call-1', $this->buildConversation());
        } finally {
            $connection->commit();
        }
        self::assertInstanceOf(ToolCall::class, $outcomeInsideATransaction);
        self::assertSame('three reviews', $outcomeInsideATransaction->result);
    }

    public function testARowWithoutTheChildsLeaseOrWithoutAnOutcomeGivesTheFailureText(): void
    {
        $this->insertCallRow(['toolCallId' => 'call-1', 'name' => 'findReviews', 'status' => AIConversationMessage::STATUS_COMPLETED, 'executionLease' => 'lease-of-another-child', 'result' => 'three reviews']);

        $outcome = $this->forkJoinService->waitForOutcome($this->buildForkWithExitedChild(1), 'call-1', $this->buildConversation());
        self::assertSame(
            'Tool error: the parallel tool worker did not report an outcome (exit code 1). Retry the call once; if it fails again, continue without it.',
            $outcome
        );
        self::assertSame(
            'Tool error: the parallel tool worker was never started for this call.',
            $this->forkJoinService->waitForOutcome(new AgentToolCallFork(), 'call-1', $this->buildConversation())
        );
    }

    /** @param array<string, mixed> $storedToolCall */
    protected function insertCallRow(array $storedToolCall): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => self::CALL_MESSAGE_ID,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => 0,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode($storedToolCall),
        ]);
    }

    /** A fork whose one child (`call-1`, the call row, the lease) already exited with `$exitCode`. */
    protected function buildForkWithExitedChild(int $exitCode): AgentToolCallFork
    {
        $childProcess = new Process([PHP_BINARY, '-r', 'exit(' . $exitCode . ');']);
        $childProcess->start();
        $agentToolCallFork = new AgentToolCallFork();
        $agentToolCallFork->processesByToolCallId['call-1'] = $childProcess;
        $agentToolCallFork->messageIdsByToolCallId['call-1'] = self::CALL_MESSAGE_ID;
        $agentToolCallFork->leasesByToolCallId['call-1'] = self::LEASE;
        $agentToolCallFork->timeoutSecondsByToolCallId['call-1'] = 5;
        return $agentToolCallFork;
    }

    /** The conversation as the turn holds it: its rows are loaded (the trace of a missing outcome reads the tool name there). */
    protected function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = self::CONVERSATION_ID;
        $conversation->messages = new AIConversationMessages();
        return $conversation;
    }
}
