<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Reads;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeInterval;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AIConversationMetricsService} against the database, on both engines: the message rows of
 * a root conversation with the values read from the tool call and the request snapshot, the egress sum of its
 * delegation children and the intervals of the batches it forked.
 */
class AIConversationMetricsServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ROOT_CONVERSATION_ID = 500;

    protected AIConversationMetricsService $metricsService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->metricsService = new AIConversationMetricsService();
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheMessageRowsCarryTheToolCallAndSnapshotValuesUnderTheirKeys(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::ROOT_CONVERSATION_ID]);
        $this->insertMessage(0, ['role' => AIConversationMessage::ROLE_USER, 'authorType' => 'USER']);
        $this->insertMessage(1, ['messageType' => AIConversationMessage::MESSAGE_TOOL_CALL, 'toolCall' => json_encode([
            'name' => 'searchPlaces', 'status' => 'FAILED', 'durationSeconds' => 1.2345, 'executionStartedAt' => '2026-11-03 10:00:01',
            'arguments' => ['query' => 'bakery'], 'result' => "Execution failed for Tool 'searchPlaces': timeout", 'wasRefusedByGate' => true,
        ])]);
        $this->insertMessage(2, ['costInUSD' => 0.02, 'inputTokens' => 100, 'requestSnapshot' => json_encode([
            'loopStepOverheadMs' => ['egress' => 1500], 'variants' => ['contextBuild' => 'v2'], 'skillSections' => ['Setup.General'],
        ])]);
        $this->insertMessage(3, ['messageType' => AIConversationMessage::MESSAGE_TOOL_CALL, 'toolCall' => json_encode([
            'name' => 'searchPlaces', 'status' => 'COMPLETED', 'wasRefusedAsDuplicate' => true, 'wasRefusedByGate' => null,
        ])]);
        // an edited-away branch is no longer part of the conversation
        $this->insertMessage(4, ['truncatedAt' => '2026-11-03 11:00:00']);

        $messageRows = $this->metricsService->findMessageRowsForConversation($this->buildRootConversation());

        self::assertSame([0, 1, 2, 3], array_map('intval', array_column($messageRows, 'i')));
        self::assertSame(
            ['i', 'role', 't', 'author', 'signal', 'c', 'u', 'snap', 'dur', 'startedAt', 'egress', 'cost', 'tool', 'args', 'toolStatus',
                'refusedFlag', 'gateRefused', 'dupRefused', 'resultHead', 'variants', 'skillSections'],
            array_keys($messageRows[0]),
            'every key keeps its case on both engines'
        );
        [$ownerText, $failedToolCall, $modelCall, $duplicateToolCall] = $messageRows;
        self::assertSame(AIConversationMessage::MESSAGE_TEXT, $ownerText['t']);
        self::assertSame(0, (int)$ownerText['snap']);
        self::assertNull($ownerText['dur']);
        self::assertSame(0, (int)$ownerText['refusedFlag']);

        self::assertSame('1.235', (string)$failedToolCall['dur']);
        self::assertSame('2026-11-03 10:00:01', $failedToolCall['startedAt']);
        self::assertSame('FAILED', $failedToolCall['toolStatus']);
        self::assertSame(['query' => 'bakery'], json_decode((string)$failedToolCall['args'], true));
        self::assertSame("Execution failed for Tool 'searchPlaces': timeout", $failedToolCall['resultHead']);
        self::assertSame([1, 1, 0], [(int)$failedToolCall['refusedFlag'], (int)$failedToolCall['gateRefused'], (int)$failedToolCall['dupRefused']]);
        self::assertSame('searchPlaces', $failedToolCall['tool']);

        self::assertSame(1, (int)$modelCall['snap']);
        self::assertSame(1500, (int)$modelCall['egress']);
        self::assertEqualsWithDelta(0.02, (float)$modelCall['cost'], 0.000001);
        self::assertSame(['contextBuild' => 'v2'], json_decode((string)$modelCall['variants'], true));
        self::assertSame(['Setup.General'], json_decode((string)$modelCall['skillSections'], true));

        self::assertSame([1, 0, 1], [(int)$duplicateToolCall['refusedFlag'], (int)$duplicateToolCall['gateRefused'], (int)$duplicateToolCall['dupRefused']], 'a JSON null flag is no refusal');
        self::assertNull($duplicateToolCall['dur']);
        self::assertNull($duplicateToolCall['egress']);
    }

    public function testTheEgressOfTheDelegationChildrenIsSummedAndTheForkedBatchesGiveTheirIntervals(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::ROOT_CONVERSATION_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 501, 'rootConversationId' => self::ROOT_CONVERSATION_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 502, 'rootConversationId' => self::ROOT_CONVERSATION_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 600]);
        $this->insertMessage(0, ['requestSnapshot' => json_encode(['loopStepOverheadMs' => ['egress' => 700]])]);
        $this->insertMessage(0, ['aiConversationId' => 501, 'requestSnapshot' => json_encode(['loopStepOverheadMs' => ['egress' => 2000]])]);
        $this->insertMessage(1, ['aiConversationId' => 501, 'requestSnapshot' => json_encode(['sentAt' => '2026-11-03 10:00:00'])]);
        $this->insertMessage(0, ['aiConversationId' => 502, 'requestSnapshot' => json_encode(['loopStepOverheadMs' => ['egress' => 500]])]);
        $this->insertMessage(0, ['aiConversationId' => 600, 'requestSnapshot' => json_encode(['loopStepOverheadMs' => ['egress' => 9999]])]);
        $batchesTable = DBAIConversationDelegationBatchModel::getTableName();
        $this->insertRow($batchesTable, ['id' => 70, 'parentConversationId' => self::ROOT_CONVERSATION_ID, 'expectedDelegationCount' => 2,
            'created' => '2026-11-03 10:00:00', 'settledAt' => '2026-11-03 10:05:00']);
        $this->insertRow($batchesTable, ['id' => 71, 'parentConversationId' => self::ROOT_CONVERSATION_ID, 'expectedDelegationCount' => 1,
            'created' => '2026-11-03 10:10:00']);
        $this->insertRow($batchesTable, ['id' => 72, 'parentConversationId' => 600, 'expectedDelegationCount' => 1, 'created' => '2026-11-03 10:20:00']);

        $rootConversation = $this->buildRootConversation();

        self::assertEqualsWithDelta(2.5, $this->metricsService->findChildModelSecondsForConversation($rootConversation), 0.000001, 'only the children of the root, the root itself not');
        $batchIntervals = $this->metricsService->findDelegationBatchIntervalsForConversation($rootConversation)->intervals;
        usort($batchIntervals, static fn (AIConversationMetricsTimeInterval $earlier, AIConversationMetricsTimeInterval $later): int => $earlier->startUnixSeconds <=> $later->startUnixSeconds);
        self::assertCount(2, $batchIntervals);
        self::assertEquals(AIConversationMetrics::toUnixSeconds('2026-11-03 10:00:00'), $batchIntervals[0]->startUnixSeconds);
        self::assertEquals(AIConversationMetrics::toUnixSeconds('2026-11-03 10:05:00'), $batchIntervals[0]->endUnixSeconds);
        self::assertEquals(AIConversationMetrics::toUnixSeconds('2026-11-03 10:10:00'), $batchIntervals[1]->startUnixSeconds);
        self::assertNull($batchIntervals[1]->endUnixSeconds);
        self::assertNotNull($this->metricsService->buildMetricsForConversation($rootConversation));
    }

    protected function buildRootConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = self::ROOT_CONVERSATION_ID;
        return $conversation;
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertMessage(int $indexInConversation, array $columnValues): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $columnValues + [
            'aiConversationId' => self::ROOT_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => '',
            'created' => '2026-11-03 10:00:00',
            'updated' => '2026-11-03 10:00:00',
        ]);
    }
}
