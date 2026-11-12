<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The compaction consistency report of one persisted conversation (formerly the
 * `array{conversationId, allPass, checks, efficiency}` of `validateCompactionConsistency()`): does the DB build of the
 * compaction tree match the in-memory truth? Built purely from what the service loaded — the FULL node set (the
 * reference), the bounded COVER and FRONTIER reads, and the message indexes of the whole conversation and of the raw
 * tail — by {@see self::buildFrom()}. Eleven named checks in a fixed order, the five efficiency counts, and
 * {@see self::toArray()} — the exact shape an application's compaction validation and evaluation commands
 * print.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionConsistencyReport extends ValueObject
{
    public int $conversationId = 0;

    /** @var int Nodes of the full set (the reference). */
    public int $totalNodes = 0;

    /** @var int Nodes the bounded cover read loaded. */
    public int $coverNodesLoaded = 0;

    /** @var int Nodes the bounded frontier read loaded. */
    public int $frontierNodesLoaded = 0;

    /** @var int Indexed messages of the whole conversation. */
    public int $totalMessages = 0;

    /** @var int Indexed messages the raw-tail read loaded. */
    public int $tailMessagesLoaded = 0;

    /** @var AIConversationCompactionConsistencyCheck[] the checks in output order */
    protected array $checks = [];

    /**
     * @param AIConversationCompactionNodes $fullNodes the full node set — the reference the full-scan cover, edge and frontier derive from
     * @param AIConversationCompactionNodes $coverNodes the bounded cover read
     * @param AIConversationCompactionNodes $exposedNodes the bounded frontier read
     * @param int[] $allMessageIndexes the indexes of every message of the conversation, ascending
     * @param int[] $tailMessageIndexes the indexes of the raw-tail read past the full-scan edge, ascending
     */
    public static function buildFrom(
        int $conversationId,
        AIConversationCompactionNodes $fullNodes,
        AIConversationCompactionNodes $coverNodes,
        AIConversationCompactionNodes $exposedNodes,
        array $allMessageIndexes,
        array $tailMessageIndexes
    ): static {
        $referenceCover = $fullNodes->buildCover();
        $referenceEdge = $referenceCover->getCoveredUpToIndex();
        $referenceFrontierNodes = [];
        foreach ($fullNodes->buildExposedNodesByLevel()->toNodesByLevel() as $levelNodes) {
            foreach ($levelNodes as $frontierNode) {
                $referenceFrontierNodes[] = $frontierNode;
            }
        }
        $expectedTailIndexes = array_values(array_filter(
            $allMessageIndexes,
            static fn (int $messageIndex): bool => $referenceEdge === null || $messageIndex > $referenceEdge
        ));

        $report = new static();
        $report->conversationId = $conversationId;
        $report->checks = [
            AIConversationCompactionConsistencyCheck::named(
                'coverDbMatchesFullScan',
                self::buildLevelAndRangeKeys($coverNodes->getElements()) === self::buildLevelAndRangeKeys($referenceCover->getCoverNodes())
            ),
            AIConversationCompactionConsistencyCheck::named(
                'frontierDbMatchesFullScan',
                self::buildSortedLevelAndRangeKeys($exposedNodes->getElements()) === self::buildSortedLevelAndRangeKeys($referenceFrontierNodes)
            ),
            AIConversationCompactionConsistencyCheck::named(
                'coverEdgeConsistent',
                $coverNodes->getCoveredUpToIndex() === $referenceEdge,
                ['edge' => $referenceEdge]
            ),
            // the cover tiles [0 .. edge]: contiguous, and its last end IS the edge
            AIConversationCompactionConsistencyCheck::named(
                'coverTilesContiguously',
                $referenceCover->tilesContiguouslyFromZero()
                    && ($referenceEdge === null || ($referenceCover->getCoveredUpToIndex() ?? -1) === $referenceEdge)
            ),
            // the raw tail is exactly the messages past the edge
            AIConversationCompactionConsistencyCheck::named(
                'tailMatchesEdge',
                $tailMessageIndexes === $expectedTailIndexes,
                ['tailCount' => count($tailMessageIndexes)]
            ),
            // nothing lost: edge+1 is the first tail index (no message gap between the covered prefix and the tail)
            AIConversationCompactionConsistencyCheck::named(
                'nothingLostBetweenCoverAndTail',
                $referenceEdge === null || $tailMessageIndexes === [] || $tailMessageIndexes[0] === $referenceEdge + 1
            ),
            AIConversationCompactionConsistencyCheck::named('everyNodeRangeAndLevelValid', $fullNodes->hasValidRangesAndLevels()),
            AIConversationCompactionConsistencyCheck::named('eachLevelIsDisjoint', $fullNodes->hasDisjointLevels()),
            AIConversationCompactionConsistencyCheck::named(
                'leavesTileContiguouslyFromZero',
                $fullNodes->hasLeavesTilingFromZero(),
                ['leafCount' => $fullNodes->countNodesAtLevel(0)]
            ),
            AIConversationCompactionConsistencyCheck::named('treeIsLaminar', $fullNodes->isLaminar()),
            AIConversationCompactionConsistencyCheck::named('rollUpsSpanContiguousChildren', $fullNodes->hasRollUpsSpanningContiguousChildren()),
        ];
        $report->totalNodes = count($fullNodes->getElements());
        $report->coverNodesLoaded = count($coverNodes->getElements());
        $report->frontierNodesLoaded = count($exposedNodes->getElements());
        $report->totalMessages = count($allMessageIndexes);
        $report->tailMessagesLoaded = count($tailMessageIndexes);
        return $report;
    }

    /**
     * The level and message range of each node as one text
     * ({@see AIConversationCompactionNode::buildLevelAndRangeKey()}), in list order: two node lists with the same
     * texts hold the same levels and ranges in the same order.
     *
     * @param AIConversationCompactionNode[] $nodes
     * @return string[]
     */
    protected static function buildLevelAndRangeKeys(array $nodes): array
    {
        return array_map(
            static fn (AIConversationCompactionNode $node): string => $node->buildLevelAndRangeKey(),
            $nodes
        );
    }

    /**
     * {@see self::buildLevelAndRangeKeys()} sorted — for a node list whose order carries no meaning (the frontier).
     *
     * @param AIConversationCompactionNode[] $nodes
     * @return string[]
     */
    protected static function buildSortedLevelAndRangeKeys(array $nodes): array
    {
        $levelAndRangeKeys = self::buildLevelAndRangeKeys($nodes);
        sort($levelAndRangeKeys, SORT_STRING);
        return $levelAndRangeKeys;
    }

    /** @return AIConversationCompactionConsistencyCheck[] the checks in output order */
    public function getChecks(): array
    {
        return $this->checks;
    }

    public function isAllPass(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->isPass()) {
                return false;
            }
        }
        return true;
    }

    /** @return array{totalNodes: int, coverNodesLoaded: int, frontierNodesLoaded: int, totalMessages: int, tailMessagesLoaded: int} */
    public function getEfficiencyCounts(): array
    {
        return [
            'totalNodes' => $this->totalNodes,
            'coverNodesLoaded' => $this->coverNodesLoaded,
            'frontierNodesLoaded' => $this->frontierNodesLoaded,
            'totalMessages' => $this->totalMessages,
            'tailMessagesLoaded' => $this->tailMessagesLoaded,
        ];
    }

    /** @return array{conversationId: int, allPass: bool, checks: array<string, array<string, bool|int|null>>, efficiency: array<string, int>} */
    public function toArray(): array
    {
        $checksByName = [];
        foreach ($this->checks as $check) {
            $checksByName[$check->getCheckName()] = $check->toArray();
        }
        return [
            'conversationId' => $this->conversationId,
            'allPass' => $this->isAllPass(),
            'checks' => $checksByName,
            'efficiency' => $this->getEfficiencyCounts(),
        ];
    }
}
