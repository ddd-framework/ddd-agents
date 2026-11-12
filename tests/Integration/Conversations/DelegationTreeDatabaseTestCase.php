<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * A database test over the rows of one delegation tree: the parent conversation, its `delegate` rows with their
 * stored sub-agent records, the batch rows (the deadline rows of sub-agent runs) and the child conversations.
 *
 * The foreign keys give the insert order: the parent conversation, the `delegate` row, the batch row, the child.
 */
abstract class DelegationTreeDatabaseTestCase extends DatabaseTestCase
{
    protected const int PARENT_CONVERSATION_ID = 100;

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** The moment `$seconds` before now as the database stores it. */
    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversation(array $columnValues): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues);
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversationOfThisEnvironment(array $columnValues): void
    {
        $this->insertConversation($columnValues + ['agentWorkingState' => json_encode(['environment' => AIConversationsService::currentEnvironment()])]);
    }

    protected function insertParentConversation(): void
    {
        $this->insertConversationOfThisEnvironment(['id' => self::PARENT_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
    }

    /** A child at work in `$batchId` under the token `token-<id>`. */
    protected function insertRunningChild(int $childConversationId, int $batchId): void
    {
        $this->insertConversationOfThisEnvironment([
            'id' => $childConversationId,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => 'token-' . $childConversationId,
            'delegationBatchId' => $batchId,
        ]);
    }

    /**
     * A batch row (the deadline row of one sub-agent run when `spawnedByMessageId` names a `delegate` row with a
     * record): of the parent conversation, one expected child, waiting for all, an hour until the deadline.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function insertBatch(array $columnValues): void
    {
        $this->insertRow(DBAIConversationDelegationBatchModel::getTableName(), $columnValues + [
            'parentConversationId' => self::PARENT_CONVERSATION_ID,
            'expectedDelegationCount' => 1,
            'delegateWakeCondition' => AIConversationDelegationResumeCondition::ALL,
            'timeoutSeconds' => 300,
            'deadlineAt' => (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s'),
            'created' => $this->formatMomentSecondsAgo(120),
        ]);
    }

    /**
     * A `delegate` call row of the parent conversation with the given stored `delegationResults` document.
     *
     * @param array<int|string, mixed> $storedDelegationResults
     */
    protected function insertDelegateRow(int $messageId, int $indexInConversation, string $toolCallStatus, array $storedDelegationResults): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::PARENT_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode(['toolCallId' => 'call-' . $messageId, 'name' => DelegateTool::NAME, 'arguments' => [], 'status' => $toolCallStatus]),
            'delegationResults' => json_encode($storedDelegationResults),
        ]);
    }

    /**
     * The stored document of a `delegate` row that carries a sub-agent record.
     *
     * @param array<int, array<string, mixed>> $storedRunsByRunNumber
     * @return array<string, mixed>
     */
    protected function buildStoredSubAgentRecord(int $childConversationId, int $currentRunNumber, array $storedRunsByRunNumber): array
    {
        return ['subAgent' => [
            'number' => 1,
            'label' => 'writer',
            'childConversationId' => $childConversationId,
            'currentRunNumber' => $currentRunNumber,
            'runs' => $storedRunsByRunNumber,
        ]];
    }

    /** @return array<string, mixed> */
    protected function findStoredConversation(int $conversationId): array
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), $conversationId);
        self::assertNotNull($storedConversation, 'conversation ' . $conversationId . ' is stored');
        return $storedConversation;
    }

    /** @return array<string, mixed> */
    protected function findStoredBatch(int $batchId): array
    {
        $storedBatch = $this->findStoredRow(DBAIConversationDelegationBatchModel::getTableName(), $batchId);
        self::assertNotNull($storedBatch, 'batch ' . $batchId . ' is stored');
        return $storedBatch;
    }

    /** @return array<string, mixed> Run `$runNumber` of the sub-agent record stored on `delegate` row `$messageId` */
    protected function findStoredRunOfDelegateRow(int $messageId, int $runNumber): array
    {
        $storedDelegateRow = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), $messageId);
        self::assertNotNull($storedDelegateRow, 'delegate row ' . $messageId . ' is stored');
        $storedDelegationResults = json_decode((string)$storedDelegateRow['delegationResults'], true);
        return $storedDelegationResults['subAgent']['runs'][(string)$runNumber];
    }
}
