<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdReadReport;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The recorded-run comparison: the conversation TREE — root, delegation children, their
 * messages, the batches, the compaction nodes as their own section — and the dispatched messages are equal after the
 * declared normalization; every other change is named with its section, row and column.
 */
class AgentRecordedRunComparisonTest extends UnitTestCase
{
    protected const array TABLE_BY_FAMILY = [
        'conversation' => 'EntityAIConversations',
        'message' => 'EntityAIConversationMessages',
        'delegationBatch' => 'EntityAIConversationDelegationBatches',
        'compactionNode' => 'EntityAIConversationCompactionNodes',
    ];

    /**
     * The normalization of a run whose tables stood just below $idBase when it started (conversations and messages
     * 6-digit ids, batches and nodes 3-digit ids) and that minted the tree of {@see treeRows()}.
     */
    protected function idNormalizationOfRun(int $idBase, int $batchBase): AgentRecordedRunIdNormalization
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([
            'EntityAIConversations' => $idBase - 1,
            'EntityAIConversationMessages' => $idBase + 99,
            'EntityAIConversationDelegationBatches' => $batchBase - 1,
            'EntityAIConversationCompactionNodes' => $batchBase + 99,
            'keywords' => 7_000_000,
        ], self::TABLE_BY_FAMILY);
        $idNormalization->addMintedIds([
            'EntityAIConversations' => [$idBase, $idBase + 1],
            'EntityAIConversationMessages' => [$idBase + 100, $idBase + 101, $idBase + 102, $idBase + 103, $idBase + 104],
            'EntityAIConversationDelegationBatches' => [$batchBase],
            'EntityAIConversationCompactionNodes' => [$batchBase + 100],
            'keywords' => [7_000_000 + $idBase],
        ]);
        return $idNormalization;
    }

    /**
     * A tree of one root (with one delegation child), five messages, one batch and one compaction node; every minted id
     * offset by $idBase / $batchBase and every stamp at $time — two runs of one build differ in exactly those.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function treeRows(int $idBase, int $batchBase, string $time, string $childAnswer = 'Rankings: 3 Keywords in den Top 10, 312 Besucher.', string $nodeSummary = 'Owner asked for rankings.', ?int $childDelegatedFromMessageId = null): array
    {
        $rootId = $idBase;
        $childId = $idBase + 1;
        $delegateCallId = $idBase + 101;
        $keywordId = 7_000_000 + $idBase;
        return [
            AgentRecordedRunComparison::SECTION_CONVERSATIONS => [
                ['id' => $rootId, 'rootConversationId' => null, 'delegatedFromMessageId' => null, 'delegationBatchId' => null, 'runState' => 'IDLE', 'shopId' => 500553,
                    'runToken' => bin2hex(random_bytes(16)), 'runHeartbeatAt' => $time, 'created' => $time, 'updated' => $time,
                    'agentWorkingState' => json_encode(['environment' => 'mgn', 'decisionModelMs' => random_int(1, 900), 'lastTurnAt' => $time, 'visitorCount' => 312])],
                ['id' => $childId, 'rootConversationId' => $rootId, 'delegatedFromMessageId' => $childDelegatedFromMessageId ?? $delegateCallId, 'delegationBatchId' => $batchBase, 'runState' => 'COMPLETED', 'shopId' => 500553,
                    'runToken' => null, 'runHeartbeatAt' => $time, 'created' => $time, 'updated' => $time, 'agentWorkingState' => null],
            ],
            AgentRecordedRunComparison::SECTION_MESSAGES => [
                ['id' => $idBase + 100, 'aiConversationId' => $rootId, 'indexInConversation' => 0, 'role' => 'USER', 'authorType' => 'USER', 'messageType' => 'TEXT', 'signalSource' => null, 'status' => null, 'message' => "Wie ranke ich? ({$time})", 'toolCall' => null, 'loadedCapabilities' => null],
                ['id' => $delegateCallId, 'aiConversationId' => $rootId, 'indexInConversation' => 1, 'role' => null, 'authorType' => 'AGENT', 'messageType' => 'TOOL_CALL', 'signalSource' => null, 'status' => null, 'message' => '',
                    'toolCall' => json_encode(['name' => 'delegate', 'status' => 'COMPLETED', 'emittedBatchId' => 'call_abc', 'result' => "child {$childId}, keyword {$keywordId} added", 'durationSeconds' => random_int(1, 9)]), 'loadedCapabilities' => null],
                ['id' => $idBase + 102, 'aiConversationId' => $rootId, 'indexInConversation' => 2, 'role' => 'ASSISTANT', 'authorType' => 'ASSISTANT', 'messageType' => 'TEXT', 'signalSource' => null, 'status' => null, 'message' => 'Du hast 3 Keywords in den Top 10.', 'toolCall' => null, 'loadedCapabilities' => null],
                ['id' => $idBase + 103, 'aiConversationId' => $childId, 'indexInConversation' => 0, 'role' => 'USER', 'authorType' => 'AGENT', 'messageType' => 'TEXT', 'signalSource' => null, 'status' => null, 'message' => 'Prüfe die Rankings.', 'toolCall' => null, 'loadedCapabilities' => null],
                ['id' => $idBase + 104, 'aiConversationId' => $childId, 'indexInConversation' => 1, 'role' => 'ASSISTANT', 'authorType' => 'ASSISTANT', 'messageType' => 'TEXT', 'signalSource' => null, 'status' => null, 'message' => $childAnswer, 'toolCall' => null, 'loadedCapabilities' => null],
            ],
            AgentRecordedRunComparison::SECTION_DELEGATION_BATCHES => [
                ['id' => $batchBase, 'parentConversationId' => $rootId, 'spawnedByMessageId' => $delegateCallId, 'expectedDelegationCount' => 1, 'delegateWakeCondition' => 'ALL', 'deadlineAt' => $time, 'settledAt' => $time, 'foldedAt' => null, 'created' => $time, 'updated' => $time],
            ],
            AgentRecordedRunComparison::SECTION_COMPACTION_NODES => [
                ['id' => $batchBase + 100, 'aiConversationId' => $rootId, 'level' => 0, 'coveredFromMessageIndex' => 0, 'coveredToMessageIndex' => 1, 'fromRawMessages' => 1, 'coveredMessageCount' => 2, 'coveredTokens' => 812, 'summaryTokens' => 40, 'summaryText' => $nodeSummary, 'preservedFacts' => '[]', 'aiModelName' => 'OPENAI.GPT6_LUNA'],
            ],
        ];
    }

    /** @return AgentRecordedRunDispatchedMessage[] */
    protected function dispatchedMessages(int $idBase, string $workspaceDirectory): array
    {
        return [
            AgentRecordedRunDispatchedMessage::forMessageClassAndBody(
                'DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage',
                (string)json_encode(['aiConversationId' => $idBase + 1, 'accountId' => 7, 'dispatchedFromWorkspaceDir' => $workspaceDirectory, 'dispatchedAtWallUs' => random_int(1, 99999)]),
                'handled'
            ),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function snapshotOf(array $treeRows, array $dispatchedMessages, AgentRecordedRunIdNormalization $idNormalization): array
    {
        $snapshot = AgentRecordedRunComparison::buildTreeSnapshot($treeRows, $idNormalization);
        $snapshot[AgentRecordedRunComparison::SECTION_DISPATCHES] = AgentRecordedRunComparison::buildDispatchSnapshot($dispatchedMessages, $idNormalization);
        return $snapshot;
    }

    public function testTwoRunsOfOneBuildAreIdenticalAcrossTheWholeTree(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(500100, 312);
        $replayedIdNormalization = $this->idNormalizationOfRun(900100, 530);
        $recordedSnapshot = $this->snapshotOf($this->treeRows(500100, 312, '2026-10-18 06:41:02'), $this->dispatchedMessages(500100, '/var/www/dev-workspaces/mgn/app'), $recordedIdNormalization);
        $replayedSnapshot = $this->snapshotOf($this->treeRows(900100, 530, '2026-10-19 08:12:55'), $this->dispatchedMessages(900100, '/var/www/dev-workspaces/other/app'), $replayedIdNormalization);

        $conversations = $recordedSnapshot[AgentRecordedRunComparison::SECTION_CONVERSATIONS];
        self::assertSame('<conversation#1>', $conversations[0]['_label']);
        self::assertSame('<conversation#1>', $conversations[1]['rootConversationId']);
        self::assertSame('<delegationBatch#1>', $conversations[1]['delegationBatchId'], 'a 3-digit batch id is replaced where it is declared');
        self::assertArrayNotHasKey('runToken', $conversations[0]);
        self::assertStringNotContainsString('decisionModelMs', (string)$conversations[0]['agentWorkingState']);
        self::assertStringContainsString('"visitorCount":312', (string)$conversations[0]['agentWorkingState'], 'a business number equal to the batch id stays raw');
        self::assertSame('<conversation#2>@0', $recordedSnapshot[AgentRecordedRunComparison::SECTION_MESSAGES][3]['_label']);
        self::assertStringContainsString('"emittedBatchId":"call_abc"', (string)$recordedSnapshot[AgentRecordedRunComparison::SECTION_MESSAGES][1]['toolCall'], 'the emission batch is compared, not dropped');
        self::assertStringContainsString('keyword <keywords#1> added', (string)$recordedSnapshot[AgentRecordedRunComparison::SECTION_MESSAGES][1]['toolCall'], 'a tool-minted id in free text is an ordinal');
        self::assertStringContainsString('312 Besucher', (string)$recordedSnapshot[AgentRecordedRunComparison::SECTION_MESSAGES][4]['message']);

        $comparison = AgentRecordedRunComparison::buildComparison(
            $recordedSnapshot,
            $replayedSnapshot,
            [AgentRecordedRunArgusRequest::forEndpointAndRequestBody('POST:https://gtw/chat', '{"conversation":500100,"child":"500101","now":"06:41","visitors":312}')],
            [AgentRecordedRunArgusRequest::forReplayedRequest('POST:https://gtw/chat', '{"conversation":900100,"child":"900101","now":"08:12","visitors":312}', 0)],
            $recordedIdNormalization,
            $replayedIdNormalization,
            []
        );
        self::assertSame([], $comparison->differences);
    }

    public function testAChangedReferenceToAnExistingRowAndABusinessNumberEqualToAnIdAreReported(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(500100, 312);
        $replayedIdNormalization = $this->idNormalizationOfRun(900100, 530);
        // the replayed child points at a message that existed before the run (at or below the mark): raw, so reported
        $recordedSnapshot = $this->snapshotOf($this->treeRows(500100, 312, '2026-10-18 06:41:02'), [], $recordedIdNormalization);
        $replayedSnapshot = $this->snapshotOf($this->treeRows(900100, 530, '2026-10-19 08:12:55', childAnswer: 'Rankings: 3 Keywords in den Top 10, 530 Besucher.', childDelegatedFromMessageId: 900150), [], $replayedIdNormalization);

        $comparison = AgentRecordedRunComparison::buildComparison($recordedSnapshot, $replayedSnapshot, [], [], $recordedIdNormalization, $replayedIdNormalization, []);

        $differencesByPrefix = [];
        foreach ($comparison->differences as $difference) {
            $differencesByPrefix[explode(': recorded «', $difference)[0]] = $difference;
        }
        self::assertArrayHasKey('conversations #1 <conversation#2> delegatedFromMessageId', $differencesByPrefix);
        self::assertStringContainsString('900150', $differencesByPrefix['conversations #1 <conversation#2> delegatedFromMessageId']);
        // a business number that happens to equal the other run's batch id is no id
        self::assertArrayHasKey('messages #4 <conversation#2>@1 message', $differencesByPrefix);
    }

    public function testAChangeInAChildAMemoryNodeTheDispatchesAndAnExtraRowIsNamedPerSection(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(500100, 312);
        $replayedIdNormalization = $this->idNormalizationOfRun(900100, 530);
        $recordedSnapshot = $this->snapshotOf($this->treeRows(500100, 312, '2026-10-18 06:41:02'), $this->dispatchedMessages(500100, '/a'), $recordedIdNormalization);
        $replayedTreeRows = $this->treeRows(900100, 530, '2026-10-19 08:12:55', childAnswer: 'Rankings: 4 Keywords in den Top 10, 312 Besucher.', nodeSummary: 'Owner asked for reviews.');
        $replayedTreeRows[AgentRecordedRunComparison::SECTION_DELEGATION_BATCHES][] = $replayedTreeRows[AgentRecordedRunComparison::SECTION_DELEGATION_BATCHES][0];
        $replayedSnapshot = $this->snapshotOf($replayedTreeRows, [], $replayedIdNormalization);

        $comparison = AgentRecordedRunComparison::buildComparison($recordedSnapshot, $replayedSnapshot, [], [], $recordedIdNormalization, $replayedIdNormalization, [3 => 'POST:https://gtw/decide']);

        self::assertSame([
            'messages #4 <conversation#2>@1 message',
            'delegationBatches: 1 recorded, 2 replayed',
            'delegationBatches #1 <delegationBatch#1>: only replayed',
            'compactionNodes #0 <compactionNode#1> summaryText',
            'dispatches: 1 recorded, 0 replayed',
            'dispatches #0 AIConversationResumeMessage: only recorded',
            'recorded call #3 POST:https://gtw/decide was never asked for',
        ], array_map(static fn (string $difference): string => explode(': recorded «', $difference)[0], $comparison->differences));
    }

    public function testTheNormalizationContinuesFromTheRecordingAndReadsBothRunsScopeIdsAlike(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(500100, 312);
        $recordedIdNormalization->addScopeIds(['shop' => 20161111, 'project' => null]);
        $recordedIdNormalization->normalizeText('first 500101');
        $restoredIdNormalization = AgentRecordedRunIdNormalization::fromArray(json_decode((string)json_encode($recordedIdNormalization->toArray()), true));
        $restoredIdNormalization->addScopeIds(['shop' => 20162222]);

        self::assertSame('<conversation#2> <shop> <shop> 99999999', $restoredIdNormalization->normalizeText('500101 20161111 20162222 99999999'));
    }

    public function testTwoRowsCreatedInTheOtherOrderAreADifferenceAndBothLayersNumberByCreation(): void
    {
        // the recording created keyword "zahnarzt" before "kieferorthopäde"; the replay the other way round
        $recordedIdNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks(['keywords' => 7_000_100], []);
        $recordedIdNormalization->addMintedIds(['keywords' => [7000101, 7000102]]);
        $replayedIdNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks(['keywords' => 9_000_200], []);
        $replayedIdNormalization->addMintedIds(['keywords' => [9000201, 9000202]]);

        $recordedText = $recordedIdNormalization->normalizeText('zahnarzt = 7000101, kieferorthopäde = 7000102');
        $replayedText = $replayedIdNormalization->normalizeText('zahnarzt = 9000202, kieferorthopäde = 9000201');
        self::assertSame('zahnarzt = <keywords#1>, kieferorthopäde = <keywords#2>', $recordedText);
        self::assertNotSame($recordedText, $replayedText, 'a reordered creation is no longer read as equal');

        // the translation of a played answer uses the same creation ordinal: recorded #1 → replayed #1
        $translation = \DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation::forRecordedCreatedIds($recordedIdNormalization->createdIdsByTable);
        $translation->translateResponseBody(
            (string)json_encode(['tool_calls' => [['function' => ['arguments' => json_encode(['keywordId' => 7000101])]]]]),
            static fn (): array => $replayedIdNormalization->createdIdsByTable,
            'recorded call #1'
        );
        self::assertSame(['recorded call #1: keywords #1 7000101 → 9000201'], $translation->translations);
        self::assertSame('<keywords#1>', $replayedIdNormalization->normalizeIdOfFamily('keywords', 9000201), 'the translated id reads as the same ordinal in the snapshot');
    }

    public function testACappedCreatedIdReadIsADifferenceAndAnUnscopedTableIsANote(): void
    {
        $comparison = new AgentRecordedRunComparison();
        $comparison->addCreatedIdReadReport(AgentRecordedRunCreatedIdReadReport::fromStoredReport(['cappedTables' => ['EntityAIUsageLogs'], 'unscopedTables' => ['keyword_sites' => ['read' => 3, 'allocated' => 5]]]), 'replay');

        self::assertSame(['replay: created-id read cap reached for EntityAIUsageLogs — the ids beyond it are not normalized'], $comparison->differences);
        self::assertSame(['replay: keyword_sites read unscoped (3 ids read, 5 allocated since the mark) — rows of other runs may count as created'], $comparison->notes);
    }
}
