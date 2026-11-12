<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationMessageFootprints;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionRollUpGroup;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionExposedNodesByLevel;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionLeafRange;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;

/**
 * Small pins the compaction equality tests rely on (the four equality tests —
 * RollUpFrontierIncrementalEqualsRecomputeTest, FrontierLoadTopDownEqualsFullScanTest,
 * CompactionCoverExactStartEqualsGapSafeTest, ExposedNodesSinglePassEqualsNestedScanTest — already exist; these add
 * what they do not pin):
 * - the field-by-field result of `AIConversationCompactionNode::buildLeafFor()` / `buildRollUpOf()` (formerly
 *   `AIConversation::buildCompactionLeafNode()` / `buildRollUpNode()`), including where `aiConversationId` comes from —
 *   a leaf: the conversation's id; a roll-up: the group's children, which agree with the
 *   conversation on a loaded and on a transient tree;
 * - the `AIConversation` shims over an unset `#[LazyLoad]` tree (the `isset` guards that must stay), the cover memo
 *   key (asOf + node count), and the roll-up group selection the future `AIConversationCompactionRollUpGroup` replaces.
 * Container-free, no doubles (pure objects).
 */
class CompactionNodeBuildAndTreeShimsCharacterizationTest extends UnitTestCase
{
    use CompactionCharacterizationFixtures;

    protected const int CONVERSATION_ID = 777;

    /** @return array<string, mixed> the persisted fields of a node, in declaration order */
    protected static function nodeFields(AIConversationCompactionNode $node): array
    {
        return [
            'aiConversationId' => $node->aiConversationId,
            'level' => $node->level,
            'coveredFromMessageIndex' => $node->coveredFromMessageIndex,
            'coveredToMessageIndex' => $node->coveredToMessageIndex,
            'summaryText' => $node->summaryText,
            'fromRawMessages' => $node->fromRawMessages,
            'aiModelName' => $node->aiModelName,
            'summaryTokens' => $node->summaryTokens,
            'coveredMessageCount' => $node->coveredMessageCount,
            'coveredTokens' => $node->coveredTokens,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The leaf and the roll-up build
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheLeafNodeFieldsFromTheRangeAndTheFootprintMap(): void
    {
        $conversation = self::fixtureConversation(self::CONVERSATION_ID);
        $conversation->messages = self::fixtureMessageSet([
            self::fixtureAssistantMessage(0),
            self::fixtureToolCallMessage(1, 'updateProfile', ['shopId' => 7]),
            self::fixtureAssistantMessage(2),
            self::fixtureAssistantMessage(4), // index 3 has no row
            self::fixtureAssistantMessage(5),
        ]);
        $preservedFacts = new AIConversationCompactionPreservedFacts();
        // footprint keyed by message index; index 3 has a footprint but no row, index 4 a row but no footprint
        $footprintByIndex = AIConversationMessageFootprints::fromTokensByIndex([0 => 100, 1 => 200, 2 => 300, 3 => 999, 5 => 50]);

        $leafNode = AIConversationCompactionNode::buildLeafFor($conversation, AIConversationCompactionLeafRange::between(1, 4), AIConversationCompactionSummary::fromSummarizerOutput('gist 1-4', 'TEST.SUMMARIZER', 42), $footprintByIndex, $preservedFacts);

        self::assertSame([
            'aiConversationId' => self::CONVERSATION_ID,
            'level' => 0,
            'coveredFromMessageIndex' => 1,
            'coveredToMessageIndex' => 4,
            'summaryText' => 'gist 1-4',
            'fromRawMessages' => true,
            'aiModelName' => 'TEST.SUMMARIZER',
            'summaryTokens' => 42,
            'coveredMessageCount' => 3,  // the LOADED rows in [1..4] — not the index span (4)
            'coveredTokens' => 500,      // Σ footprint over those rows' indexes: 200 + 300 + (index 4: none → 0); index 3's 999 has no row
        ], self::nodeFields($leafNode));
        self::assertSame($preservedFacts, $leafNode->preservedFacts, 'the passed facts are the node\'s — never rebuilt');
    }

    public function testATransientConversationBuildsALeafWithoutAConversationId(): void
    {
        $conversation = self::fixtureConversation();
        $conversation->messages = self::fixtureMessageSet([self::fixtureAssistantMessage(0), self::fixtureAssistantMessage(1)]);

        $leafNode = AIConversationCompactionNode::buildLeafFor($conversation, AIConversationCompactionLeafRange::between(0, 1), AIConversationCompactionSummary::fromSummarizerOutput('gist', null, null), AIConversationMessageFootprints::fromTokensByIndex([0 => 10, 1 => 20]), new AIConversationCompactionPreservedFacts());

        self::assertNull($leafNode->aiConversationId);
        self::assertSame(30, $leafNode->coveredTokens);
        self::assertSame(2, $leafNode->coveredMessageCount);
    }

    /** @return AIConversationCompactionNode[] two leaves of conversation $childConversationId with counts, tokens and facts */
    protected static function rollUpChildren(?int $childConversationId): array
    {
        $firstChild = self::fixtureNodeWithTelemetry(0, 9, AIConversationCompactionToolTelemetry::fromMessages([
            self::fixtureToolCallMessage(3, 'updateProfile', ['v' => 3], 'saved'),
        ]));
        $firstChild->aiConversationId = $childConversationId;
        $firstChild->coveredMessageCount = 10;
        $firstChild->coveredTokens = 1200;
        $firstChild->preservedFacts->identifiers = ['shopId 7', 'keyword pizza'];
        $firstChild->preservedFacts->standingConstraints = ['no emojis'];
        $secondChild = self::fixtureNodeWithTelemetry(10, 19, null); // a child written before the telemetry slot
        $secondChild->aiConversationId = $childConversationId;
        $secondChild->coveredMessageCount = 10;
        $secondChild->coveredTokens = null; // QUIRK: an unstamped child counts 0 (`?? 0`)
        $secondChild->preservedFacts->identifiers = ['keyword pizza', 'postId 12'];
        $secondChild->preservedFacts->standingConstraints = ['no emojis', 'formal address'];
        return [$firstChild, $secondChild];
    }

    public function testTheRollUpNodeFieldsFromItsChildren(): void
    {
        $rollUpNode = AIConversationCompactionNode::buildRollUpOf(AIConversationCompactionRollUpGroup::ofChildNodes(self::rollUpChildren(self::CONVERSATION_ID)), AIConversationCompactionSummary::fromSummarizerOutput('roll-up gist', 'TEST.SUMMARIZER', 77));

        self::assertSame([
            'aiConversationId' => self::CONVERSATION_ID,
            'level' => 1,
            'coveredFromMessageIndex' => 0,
            'coveredToMessageIndex' => 19,
            'summaryText' => 'roll-up gist',
            'fromRawMessages' => false,
            'aiModelName' => 'TEST.SUMMARIZER',
            'summaryTokens' => 77,
            'coveredMessageCount' => 20,
            'coveredTokens' => 1200,
        ], self::nodeFields($rollUpNode));
        // identifiers and constraints merged verbatim, first-seen order, de-duplicated
        self::assertSame(['shopId 7', 'keyword pizza', 'postId 12'], $rollUpNode->preservedFacts->identifiers);
        self::assertSame(['no emojis', 'formal address'], $rollUpNode->preservedFacts->standingConstraints);
        // the telemetry is merge()d: a pre-slot child makes the counts incomplete, the known count survives
        self::assertSame(['updateProfile' => 1], $rollUpNode->preservedFacts->toolTelemetry?->getCompletedCountsByToolName());
        self::assertTrue($rollUpNode->preservedFacts->toolTelemetry->countsIncomplete);
    }

    /**
     * `aiConversationId` of a roll-up is the CHILDREN's (formerly it was the conversation's
     * `$this->id ?? null`). On a loaded tree the children carry the conversation's id, on a transient one they carry
     * null, so both sources agree on every tree a round builds. A child set with another conversation's id is not
     * produced by any code path (a round rolls only the nodes of the conversation it loaded); it shows the source now.
     */
    public function testTheRollUpConversationIdComesFromTheChildren(): void
    {
        $loadedRollUp = AIConversationCompactionNode::buildRollUpOf(AIConversationCompactionRollUpGroup::ofChildNodes(self::rollUpChildren(self::CONVERSATION_ID)), AIConversationCompactionSummary::fromSummarizerOutput('g', null, null));
        self::assertSame(self::CONVERSATION_ID, $loadedRollUp->aiConversationId, 'loaded tree: the children\'s id = the conversation id');

        $transientRollUp = AIConversationCompactionNode::buildRollUpOf(AIConversationCompactionRollUpGroup::ofChildNodes(self::rollUpChildren(null)), AIConversationCompactionSummary::fromSummarizerOutput('g', null, null));
        self::assertNull($transientRollUp->aiConversationId, 'transient tree: the children\'s null');

        $rollUpOverForeignChildren = AIConversationCompactionNode::buildRollUpOf(AIConversationCompactionRollUpGroup::ofChildNodes(self::rollUpChildren(5)), AIConversationCompactionSummary::fromSummarizerOutput('g', null, null));
        self::assertSame(5, $rollUpOverForeignChildren->aiConversationId, 'the children are the source (before b3: the conversation\'s null)');
    }

    public function testTheRollUpLevelAndRangeReadOnlyTheFirstAndLastChild(): void
    {
        // QUIRK: level = FIRST child's level + 1, range = first.from … last.to — the children are not checked
        $mixedChildren = [self::fixtureNode(1, 0, 19), self::fixtureNode(0, 20, 24), self::fixtureNode(0, 30, 34)];
        foreach ($mixedChildren as $mixedChild) {
            $mixedChild->preservedFacts = new AIConversationCompactionPreservedFacts();
        }

        $rollUpNode = AIConversationCompactionNode::buildRollUpOf(AIConversationCompactionRollUpGroup::ofChildNodes($mixedChildren), AIConversationCompactionSummary::fromSummarizerOutput('g', null, null));

        self::assertSame([2, 0, 34], [$rollUpNode->level, $rollUpNode->coveredFromMessageIndex, $rollUpNode->coveredToMessageIndex]);
        self::assertSame(0, $rollUpNode->coveredMessageCount, 'unset counts sum to 0');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The AIConversation shims, the cover memo, the roll-up group selection
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheShimsOverAnUnsetTreeAnswerEmptyWithoutLoading(): void
    {
        $conversation = self::fixtureConversation(self::CONVERSATION_ID); // compactionNodes uninitialized (a #[LazyLoad] property)

        self::assertSame([], $conversation->buildCompactionCover()->getCoverNodes());
        self::assertNull($conversation->getCompactionCoveredUpToIndex());
        self::assertNull($conversation->buildCompactionSummaryText());
        self::assertNull($conversation->getNextRollUpGroup(0, 2, 0));
        self::assertSame([], $conversation->getExposedCompactionNodesAtLevel(0)->getElements());
        self::assertFalse(isset($conversation->compactionNodes), 'no shim loaded the tree');
    }

    public function testTheShimsDelegateToTheLoadedSet(): void
    {
        $conversation = self::fixtureConversation(self::CONVERSATION_ID);
        $nodes = [self::fixtureNode(0, 0, 4), self::fixtureNode(0, 5, 9), self::fixtureNode(0, 10, 14)];
        $conversation->compactionNodes = self::fixtureNodeSet($nodes);

        self::assertSame([[0, 0, 4], [0, 5, 9], [0, 10, 14]], self::fixtureShape($conversation->buildCompactionCover()->getCoverNodes()));
        self::assertSame(14, $conversation->getCompactionCoveredUpToIndex());
        self::assertSame("[messages 0–4]\nL0 [0-4]\n\n[messages 5–9]\nL0 [5-9]\n\n[messages 10–14]\nL0 [10-14]", $conversation->buildCompactionSummaryText());
        self::assertSame([[0, 0, 4], [0, 5, 9]], self::fixtureShape($conversation->getNextRollUpGroup(0, 2, 1)?->getChildNodes() ?? []));
        self::assertSame(self::fixtureShape($nodes), self::fixtureShape($conversation->getExposedCompactionNodesAtLevel(0)->getElements()));
    }

    /**
     * QUIRK (the memo lives in the cover VO with the same key): the memo key is the asOf timestamp + the NODE COUNT,
     * so a node changed in place (same count) is answered from the memo, while an append re-derives.
     */
    public function testTheCoverMemoIsKeyedOnTheNodeCount(): void
    {
        $firstLeaf = self::fixtureNode(0, 0, 4);
        $nodeSet = self::fixtureNodeSet([$firstLeaf, self::fixtureNode(0, 5, 9)]);
        $firstCover = $nodeSet->buildCover();
        self::assertSame([[0, 0, 4], [0, 5, 9]], self::fixtureShape($firstCover->getCoverNodes()));

        $firstLeaf->coveredToMessageIndex = 2; // in-place change: a hole at 3..4, same node count
        self::assertSame($firstCover, $nodeSet->buildCover(), 'answered from the memo — the hole is not seen');
        self::assertSame(9, $nodeSet->getCoveredUpToIndex());

        self::fixtureSetProperty($nodeSet, 'elements', [$firstLeaf, self::fixtureNode(0, 5, 9), self::fixtureNode(0, 10, 14)]);
        self::fixtureSetProperty($nodeSet, 'elementCount', 3);
        self::assertSame([[0, 0, 2]], self::fixtureShape($nodeSet->buildCover()->getCoverNodes()), 'a new count re-derives — the gap-safe greedy stops at the hole');
    }

    /** @return array<string, array{0: array<int, array{0: int, 1: int}>, 1: int, 2: int, 3: array<int, array{0: int, 1: int, 2: int}>|null}> */
    public static function rollUpGroupCases(): array
    {
        $fiveLeaves = [[0, 4], [5, 9], [10, 14], [15, 19], [20, 24]];
        return [
            'the oldest K beyond the retention' => [$fiveLeaves, 2, 3, [[0, 0, 4], [0, 5, 9]]],
            'retention 0 rolls the oldest K' => [$fiveLeaves, 5, 0, [[0, 0, 4], [0, 5, 9], [0, 10, 14], [0, 15, 19], [0, 20, 24]]],
            'too few beyond the retention' => [$fiveLeaves, 3, 3, null],
            'a fan-in below 2 never rolls' => [$fiveLeaves, 1, 0, null],
            // QUIRK: a negative retention counts as 0 (`max(0, …)`)
            'a negative retention counts as 0' => [$fiveLeaves, 5, -2, [[0, 0, 4], [0, 5, 9], [0, 10, 14], [0, 15, 19], [0, 20, 24]]],
            // QUIRK: a hole inside the OLDEST K answers null — the selection never looks past it to a later contiguous run
            'a hole in the oldest K stops the level' => [[[0, 4], [6, 9], [10, 14], [15, 19], [20, 24]], 2, 0, null],
        ];
    }

    /**
     * @param array<int, array{0: int, 1: int}> $leafRanges
     * @param array<int, array{0: int, 1: int, 2: int}>|null $expectedGroupShape
     */
    #[DataProvider('rollUpGroupCases')]
    public function testTheRollUpGroupSelection(array $leafRanges, int $fanIn, int $retentionPerLevel, ?array $expectedGroupShape): void
    {
        $leaves = array_map(static fn (array $leafRange): AIConversationCompactionNode => self::fixtureNode(0, $leafRange[0], $leafRange[1]), $leafRanges);
        $nodeSet = self::fixtureNodeSet($leaves);

        $pickedGroup = AIConversationCompactionExposedNodesByLevel::fromNodesByLevel([0 => $nodeSet->getExposedNodesAtLevel(0)->getElements()])
            ->buildNextRollUpGroupAtLevel(0, $fanIn, $retentionPerLevel)?->getChildNodes();
        $nextGroup = $nodeSet->getNextRollUpGroup(0, $fanIn, $retentionPerLevel)?->getChildNodes();

        self::assertSame($expectedGroupShape, $pickedGroup === null ? null : self::fixtureShape($pickedGroup));
        self::assertSame($expectedGroupShape, $nextGroup === null ? null : self::fixtureShape($nextGroup), 'getNextRollUpGroup = the pick over the exposed nodes');
        // the group's members are the set's own node objects, not copies
        if ($nextGroup !== null) {
            self::assertSame($leaves[0], $nextGroup[0]);
        }
    }

    public function testTheGroupOfTheServicesIncrementalLoopIsTheSameAsTheOneOverTheByLevelMap(): void
    {
        // the round picks from buildExposedNodesByLevel()[$level] (AIConversationCompactionService::cascadeRollUps), the
        // shim from getExposedNodesAtLevel($level): same nodes, same group
        $nodes = [self::fixtureNode(1, 0, 19)];
        for ($leafIndex = 0; $leafIndex < 8; $leafIndex++) {
            $nodes[] = self::fixtureNode(0, $leafIndex * 5, $leafIndex * 5 + 4);
        }
        $nodeSet = self::fixtureNodeSet($nodes);

        $groupFromByLevel = $nodeSet->buildExposedNodesByLevel()->buildNextRollUpGroupAtLevel(0, 2, 1)?->getChildNodes();
        $groupFromShim = $nodeSet->getNextRollUpGroup(0, 2, 1)?->getChildNodes();

        self::assertSame([[0, 20, 24], [0, 25, 29]], self::fixtureShape($groupFromByLevel ?? []));
        self::assertSame($groupFromByLevel, $groupFromShim);
    }
}
