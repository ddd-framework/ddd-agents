<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Embeddings;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairReport;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairSettings;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\AI\Services\Conversations\AIConversationEmbeddingsRepairService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The statements of the embedding repair ({@see AIConversationEmbeddingsRepairService}) against the database, on
 * both engines: the scan finds exactly the rows that hold the zero vector, a writing run re-embeds a compaction node
 * from its text and never overwrites a vector another writer stored meanwhile, and a message embedding row without a
 * message worth embedding is deleted.
 *
 * A row inserted without a vector holds the zero vector — the default of the vector column on both engines.
 */
class AIConversationEmbeddingsRepairServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 10;
    protected const int CONVERSATION_ID = 1;

    protected const int TEXT_MESSAGE_ID = 101;
    protected const int EMPTY_TEXT_MESSAGE_ID = 102;
    protected const int TOOL_CALL_MESSAGE_ID = 103;

    protected const int NODE_WITH_TEXT_ID = 201;
    protected const int NODE_WITHOUT_TEXT_ID = 202;
    protected const int NODE_WITH_LONG_TEXT_ID = 203;

    protected EmbeddingsRepairServiceWithRecordingEmbeddingCalls $repairService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->repairService = new EmbeddingsRepairServiceWithRecordingEmbeddingCalls();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'accountId' => self::ACCOUNT_ID]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testADryRunCountsAndClassifiesTheZeroRowsAndWritesNothing(): void
    {
        $this->insertTheThreeMessagesWithAZeroEmbeddingRowEach();
        $this->insertTheThreeCompactionNodes();

        $report = $this->repairService->repairZeroEmbeddings($this->buildSettings(AIConversationEmbeddingsRepairSettings::TARGET_ALL, dryRun: true));

        self::assertSame(AIConversationEmbeddingsRepairReport::STOPPED_SCAN_COMPLETE, $report->stoppedBecause);
        self::assertNull($report->runError);
        self::assertSame(
            [3, 1, 2, 0],
            [
                $report->zeroMessageEmbeddingRowsFound,
                $report->messageEmbeddingRowsToReembed,
                $report->messageEmbeddingRowsWithoutRecallUnit,
                $report->messageEmbeddingRowsDeleted,
            ]
        );
        self::assertSame(
            [3, 1, 0, self::NODE_WITH_LONG_TEXT_ID],
            [
                $report->compactionNodes->zeroRowsFound,
                $report->compactionNodes->rowsOverInputLimit,
                $report->compactionNodes->rowsReembedded,
                $report->compactionNodes->lastId,
            ]
        );
        self::assertSame(0, $report->memories->zeroRowsFound, 'the package names no memory table');
        self::assertSame([], $this->repairService->embeddedTexts);
        self::assertSame([], $this->repairService->messageIdsHandedToTheWriter);
        self::assertSame(3, $this->countMessageEmbeddingRows());
        self::assertSame(3, $this->countCompactionNodesWithVectorOfOneValue(0.0));
    }

    public function testAWritingRunReembedsACompactionNodeFromItsTextAndLeavesANodeWithoutTextAlone(): void
    {
        $this->insertTheThreeCompactionNodes();

        $report = $this->repairService->repairZeroEmbeddings($this->buildSettings(AIConversationEmbeddingsRepairSettings::TARGET_NODES, dryRun: false));

        self::assertNull($report->runError);
        self::assertSame([], $this->repairService->recordedFailureConsequences);
        self::assertSame(
            [3, 2, 1, 0],
            [
                $report->compactionNodes->zeroRowsFound,
                $report->compactionNodes->rowsReembedded,
                $report->compactionNodes->rowsWithoutText,
                $report->compactionNodes->rowsFailed,
            ]
        );
        self::assertSame(['what the owner asked for', str_repeat('a', TextEmbedding::FIRST_ATTEMPT_MAX_INPUT_BYTES + 1)], $this->repairService->embeddedTexts);
        self::assertSame(
            2,
            $this->countCompactionNodesWithVectorOfOneValue(EmbeddingsRepairServiceWithRecordingEmbeddingCalls::ANSWERED_VECTOR_VALUE)
        );
        self::assertSame(1, $this->countCompactionNodesWithVectorOfOneValue(0.0), 'the node without text keeps the zero vector');

        // a repaired row no longer holds the zero vector: the next run finds the node without text only
        $secondReport = $this->repairService->repairZeroEmbeddings($this->buildSettings(AIConversationEmbeddingsRepairSettings::TARGET_NODES, dryRun: false));
        self::assertSame([1, 0, 1], [
            $secondReport->compactionNodes->zeroRowsFound,
            $secondReport->compactionNodes->rowsReembedded,
            $secondReport->compactionNodes->rowsWithoutText,
        ]);
    }

    public function testTheScanStartsAfterTheGivenIdAndReadsOneIdWindowPerQuery(): void
    {
        $this->insertTheThreeCompactionNodes();
        $settings = $this->buildSettings(AIConversationEmbeddingsRepairSettings::TARGET_NODES, dryRun: true);
        $settings->afterCompactionNodeId = self::NODE_WITH_TEXT_ID;
        $settings->scanWindow = 1;

        $report = $this->repairService->repairZeroEmbeddings($settings);

        self::assertSame(2, $report->compactionNodes->zeroRowsFound, 'the node the scan starts after is not read');
        self::assertSame(2, $report->scanQueries);
        self::assertSame(self::NODE_WITH_LONG_TEXT_ID, $report->compactionNodes->lastId);
    }

    public function testTheScanMeasuresATextInBytesOnBothEngines(): void
    {
        $textOfTwelveTwoByteCharacters = str_repeat('é', 12);
        $this->insertCompactionNode(self::NODE_WITH_TEXT_ID, 0, $textOfTwelveTwoByteCharacters);
        $this->insertMessageWithZeroEmbeddingRow(self::TEXT_MESSAGE_ID, 0, AIConversationMessage::MESSAGE_TEXT, $textOfTwelveTwoByteCharacters);
        $compactionNodesTarget = AgentEmbeddingRepairTarget::forModel(DBAIConversationCompactionNodeModel::class, 'summaryText', 'summaryEmbedding');

        $zeroVectorRows = $this->repairService->findZeroVectorRowsOfIdWindow($compactionNodesTarget, 0, self::NODE_WITH_TEXT_ID);
        $zeroMessageEmbeddingRows = $this->repairService->findZeroMessageEmbeddingRowsOfIdWindow(0, PHP_INT_MAX);

        self::assertCount(1, $zeroVectorRows);
        self::assertSame(24, $zeroVectorRows[0]->textBytes);
        self::assertCount(1, $zeroMessageEmbeddingRows);
        self::assertSame(24, $zeroMessageEmbeddingRows[0]->messageBytes);
    }

    public function testAVectorAnotherWriterStoredMeanwhileIsNotOverwritten(): void
    {
        $this->insertTheThreeCompactionNodes();
        $compactionNodesTarget = AgentEmbeddingRepairTarget::forModel(DBAIConversationCompactionNodeModel::class, 'summaryText', 'summaryEmbedding');

        $this->repairService->writeVectorOfRowIfStillZero(
            $compactionNodesTarget,
            self::NODE_WITH_TEXT_ID,
            EmbeddingsRepairServiceWithRecordingEmbeddingCalls::buildVectorOfOneValue(0.25)
        );
        $this->repairService->writeVectorOfRowIfStillZero(
            $compactionNodesTarget,
            self::NODE_WITH_TEXT_ID,
            EmbeddingsRepairServiceWithRecordingEmbeddingCalls::buildVectorOfOneValue(0.75)
        );

        self::assertSame(1, $this->countCompactionNodesWithVectorOfOneValue(0.25));
        self::assertSame(0, $this->countCompactionNodesWithVectorOfOneValue(0.75));
        self::assertSame(2, $this->countCompactionNodesWithVectorOfOneValue(0.0));
    }

    public function testAWritingRunDeletesTheRowsWithoutAMessageWorthEmbeddingAndHandsTheOthersToTheWriter(): void
    {
        $this->insertTheThreeMessagesWithAZeroEmbeddingRowEach();

        $report = $this->repairService->repairZeroEmbeddings($this->buildSettings(AIConversationEmbeddingsRepairSettings::TARGET_MESSAGES, dryRun: false));

        self::assertNull($report->runError);
        self::assertSame([], $this->repairService->recordedFailureConsequences);
        self::assertSame(
            [3, 1, 2, 0],
            [
                $report->zeroMessageEmbeddingRowsFound,
                $report->messageEmbeddingRowsReembedded,
                $report->messageEmbeddingRowsDeleted,
                $report->messageEmbeddingRowsFailed,
            ]
        );
        self::assertSame([self::TEXT_MESSAGE_ID], $this->repairService->messageIdsHandedToTheWriter);
        self::assertSame(1, $this->countMessageEmbeddingRows());
        self::assertSame(
            [self::TEXT_MESSAGE_ID],
            array_map('intval', $this->getDatabaseConnection()->fetchFirstColumn(
                'SELECT ' . $this->getSqlDialect()->quote('aiConversationMessageId')
                . ' FROM ' . $this->getSqlDialect()->quote(DBAIConversationMessageEmbeddingModel::getTableName())
            ))
        );
    }

    protected function buildSettings(string $target, bool $dryRun): AIConversationEmbeddingsRepairSettings
    {
        $settings = new AIConversationEmbeddingsRepairSettings();
        $settings->target = $target;
        $settings->dryRun = $dryRun;
        $settings->sleepMs = 0;
        return $settings;
    }

    /** A text message, a text message without text and a tool call, each with an embedding row that holds the zero vector. */
    protected function insertTheThreeMessagesWithAZeroEmbeddingRowEach(): void
    {
        $this->insertMessageWithZeroEmbeddingRow(self::TEXT_MESSAGE_ID, 0, AIConversationMessage::MESSAGE_TEXT, 'Which of my pages rank?');
        $this->insertMessageWithZeroEmbeddingRow(self::EMPTY_TEXT_MESSAGE_ID, 1, AIConversationMessage::MESSAGE_TEXT, '   ');
        $this->insertMessageWithZeroEmbeddingRow(self::TOOL_CALL_MESSAGE_ID, 2, AIConversationMessage::MESSAGE_TOOL_CALL, 'a tool call is no recall unit');
    }

    protected function insertMessageWithZeroEmbeddingRow(int $messageId, int $indexInConversation, string $messageType, string $messageText): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_USER,
            'authorType' => AIConversationMessage::AUTHOR_USER,
            'messageType' => $messageType,
            'message' => $messageText,
        ]);
        $this->insertRow(DBAIConversationMessageEmbeddingModel::getTableName(), ['aiConversationMessageId' => $messageId]);
    }

    /** A node with a summary, a node whose summary is empty and a node whose summary is longer than one embedding input. */
    protected function insertTheThreeCompactionNodes(): void
    {
        $this->insertCompactionNode(self::NODE_WITH_TEXT_ID, 0, 'what the owner asked for');
        $this->insertCompactionNode(self::NODE_WITHOUT_TEXT_ID, 10, '');
        $this->insertCompactionNode(self::NODE_WITH_LONG_TEXT_ID, 20, str_repeat('a', TextEmbedding::FIRST_ATTEMPT_MAX_INPUT_BYTES + 1));
    }

    protected function insertCompactionNode(int $nodeId, int $coveredFromMessageIndex, string $summaryText): void
    {
        $this->insertRow(DBAIConversationCompactionNodeModel::getTableName(), [
            'id' => $nodeId,
            'aiConversationId' => self::CONVERSATION_ID,
            'level' => 0,
            'coveredFromMessageIndex' => $coveredFromMessageIndex,
            'coveredToMessageIndex' => $coveredFromMessageIndex + 9,
            'summaryText' => $summaryText,
            'fromRawMessages' => 0,
        ]);
    }

    protected function countMessageEmbeddingRows(): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote(DBAIConversationMessageEmbeddingModel::getTableName())
        );
    }

    /** How many compaction nodes hold the vector whose elements are all `$elementValue` (0.0: the zero vector). */
    protected function countCompactionNodesWithVectorOfOneValue(float $elementValue): int
    {
        return (int)EntityManagerFactory::getInstance()->createQuery(
            'SELECT COUNT(compactionNode.id) FROM ' . DBAIConversationCompactionNodeModel::class . ' compactionNode'
            . ' WHERE compactionNode.summaryEmbedding = VEC_FROM_TEXT(:vectorText)'
        )->setParameters([
            'vectorText' => json_encode(EmbeddingsRepairServiceWithRecordingEmbeddingCalls::buildVectorOfOneValue($elementValue)->vectorValues, JSON_THROW_ON_ERROR),
        ])->getSingleScalarResult();
    }
}
