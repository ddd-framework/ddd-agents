<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionLeafRange;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionRollUpGroup;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationMessageFootprints;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * {@see AIConversationCompactionNode::buildLeafFor()} and {@see AIConversationCompactionNode::buildRollUpOf()}
 * against the two builders they replace, `AIConversation::buildCompactionLeafNode()` / `buildRollUpNode()`, copied
 * below verbatim as the reference (the conversation's id and raw rows passed in). Compared field by field, the
 * preserved facts as their stored JSON, over every range of a conversation with gaps, tool rows and owner quotes, for
 * a loaded and a transient conversation, and over roll-up groups of every width.
 */
class CompactionNodeBuildDifferentialTest extends UnitTestCase
{
    use CompactionCharacterizationFixtures;

    /** `AIConversation::buildCompactionLeafNode()` before b3, verbatim; `$conversation` stands for `$this`. */
    protected static function referenceLeafNode(
        AIConversation $conversation,
        int $fromIndex,
        int $toIndex,
        string $summaryText,
        ?string $aiModelName = null,
        ?int $summaryTokens = null,
        ?AIConversationMessageFootprints $footprints = null,
        ?AIConversationCompactionPreservedFacts $preservedFacts = null
    ): AIConversationCompactionNode {
        $leafNode = new AIConversationCompactionNode();
        $leafNode->aiConversationId = $conversation->id ?? null;
        $leafNode->level = 0;
        $leafNode->coveredFromMessageIndex = $fromIndex;
        $leafNode->coveredToMessageIndex = $toIndex;
        $leafNode->summaryText = $summaryText;
        $leafNode->fromRawMessages = true;
        $leafNode->aiModelName = $aiModelName;
        $leafNode->summaryTokens = $summaryTokens;
        $leafNode->preservedFacts = $preservedFacts ?? $conversation->buildPreservedFactsForRange($fromIndex, $toIndex);
        $rawMessagesInRange = (($conversation->messages ?? null)?->getRowsInIndexRange($fromIndex, $toIndex) ?? []);
        $leafNode->coveredMessageCount = count($rawMessagesInRange);
        $footprints ??= $conversation->buildMessageFootprints();
        $leafNode->coveredTokens = $footprints->sumOverMessages($rawMessagesInRange);
        return $leafNode;
    }

    /** `AIConversation::buildRollUpNode()` before b3, verbatim; `$conversation` stands for `$this`. */
    protected static function referenceRollUpNode(
        AIConversation $conversation,
        AIConversationCompactionRollUpGroup $rollUpGroup,
        string $summaryText,
        ?string $aiModelName = null,
        ?int $summaryTokens = null
    ): AIConversationCompactionNode {
        $firstChild = $rollUpGroup->getChildNodes()[0];
        $rollUpNode = new AIConversationCompactionNode();
        $rollUpNode->aiConversationId = $conversation->id ?? null;
        $rollUpNode->level = ($firstChild->level ?? 0) + 1;
        $rollUpNode->coveredFromMessageIndex = $rollUpGroup->getCoveredFromMessageIndex();
        $rollUpNode->coveredToMessageIndex = $rollUpGroup->getCoveredToMessageIndex();
        $rollUpNode->summaryText = $summaryText;
        $rollUpNode->fromRawMessages = false;
        $rollUpNode->aiModelName = $aiModelName;
        $rollUpNode->summaryTokens = $summaryTokens;
        $rollUpNode->preservedFacts = AIConversationCompactionPreservedFacts::mergeOf($rollUpGroup->getChildNodes());
        $rollUpNode->coveredMessageCount = $rollUpGroup->sumCoveredMessageCount();
        $rollUpNode->coveredTokens = $rollUpGroup->sumCoveredTokens();
        return $rollUpNode;
    }

    /** @return array<string, mixed> every stored field, the preserved facts as their stored JSON */
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
            'preservedFacts' => json_encode($node->preservedFacts->toObject()),
        ];
    }

    protected static function conversationWithRows(?int $conversationId): AIConversation
    {
        $conversation = self::fixtureConversation($conversationId);
        $rows = [];
        foreach ([0, 1, 2, 3, 5, 6, 7, 9, 10, 11] as $position => $index) { // gaps at 4 and 8
            $row = $position % 3 === 1
                ? self::fixtureToolCallMessage($index, 'addKeywords', ['shopId' => 7, 'keywords' => ['pizza ' . $index]], 'saved ' . $index)
                : self::fixtureAssistantMessage($index, $position % 3 === 0 ? 1000 + 300 * $index : null);
            $row->outputTokens = 20 + $index;
            $rows[] = $row;
        }
        $conversation->messages = self::fixtureMessageSet($rows);
        return $conversation;
    }

    public function testTheLeafEqualsTheFormerBuilderOverEveryRange(): void
    {
        $caseCount = 0;
        foreach ([4242, null] as $conversationId) {
            $conversation = self::conversationWithRows($conversationId);
            $footprintVariants = [
                'built' => $conversation->buildMessageFootprints(),
                'given' => AIConversationMessageFootprints::fromTokensByIndex([1 => 11, 3 => 33, 4 => 999, 6 => 66, 11 => 111]),
            ];
            foreach (range(0, 12) as $fromIndex) {
                foreach (range($fromIndex, 12) as $toIndex) {
                    foreach ($footprintVariants as $footprintVariant => $footprints) {
                        foreach ([['gist', 'TEST.SUMMARIZER', 42], ['', null, null]] as [$summaryText, $aiModelName, $outputTokens]) {
                            $preservedFacts = $conversation->buildPreservedFactsForRange($fromIndex, $toIndex);
                            $expected = self::referenceLeafNode($conversation, $fromIndex, $toIndex, $summaryText, $aiModelName, $outputTokens, $footprints, $preservedFacts);
                            $actual = AIConversationCompactionNode::buildLeafFor(
                                $conversation,
                                AIConversationCompactionLeafRange::between($fromIndex, $toIndex),
                                AIConversationCompactionSummary::fromSummarizerOutput($summaryText, $aiModelName, $outputTokens),
                                $footprints,
                                $preservedFacts
                            );
                            self::assertSame(self::nodeFields($expected), self::nodeFields($actual), "[$fromIndex..$toIndex] $footprintVariant");
                            $caseCount++;
                        }
                    }
                }
            }
            // the degenerate fallback's footprints: the former builder built them itself, the round now builds them once
            $expected = self::referenceLeafNode($conversation, 0, 11, 'gist');
            $actual = AIConversationCompactionNode::buildLeafFor($conversation, AIConversationCompactionLeafRange::between(0, 11), AIConversationCompactionSummary::fromSummarizerOutput('gist', null, null), $conversation->buildMessageFootprints(), $conversation->buildPreservedFactsForRange(0, 11));
            self::assertSame(self::nodeFields($expected), self::nodeFields($actual), 'null footprints and facts');
        }
        self::assertSame(728, $caseCount);
    }

    public function testTheRollUpEqualsTheFormerBuilderOnALoadedAndATransientTree(): void
    {
        $caseCount = 0;
        foreach ([4242, null] as $conversationId) {
            $children = [];
            for ($childIndex = 0; $childIndex < 6; $childIndex++) {
                $child = self::fixtureNodeWithTelemetry($childIndex * 10, $childIndex * 10 + 9, $childIndex === 2 ? null : AIConversationCompactionToolTelemetry::fromMessages([
                    self::fixtureToolCallMessage($childIndex, 'updateProfile', ['v' => $childIndex], 'saved'),
                ]));
                $child->aiConversationId = $conversationId; // the round's children carry the conversation's id (null when transient)
                $child->coveredMessageCount = $childIndex === 3 ? null : 10;
                $child->coveredTokens = $childIndex === 4 ? null : 100 * ($childIndex + 1);
                $child->preservedFacts->identifiers = ['shopId 7', 'keyword ' . $childIndex];
                $child->preservedFacts->standingConstraints = ['no emojis', 'rule ' . ($childIndex % 2)];
                $children[] = $child;
            }
            $conversation = self::fixtureConversation($conversationId);
            for ($width = 1; $width <= 6; $width++) {
                for ($offset = 0; $offset + $width <= 6; $offset++) {
                    $rollUpGroup = AIConversationCompactionRollUpGroup::ofChildNodes(array_slice($children, $offset, $width));
                    $expected = self::referenceRollUpNode($conversation, $rollUpGroup, 'rolled', 'TEST.SUMMARIZER', 9);
                    $actual = AIConversationCompactionNode::buildRollUpOf($rollUpGroup, AIConversationCompactionSummary::fromSummarizerOutput('rolled', 'TEST.SUMMARIZER', 9));
                    self::assertSame(self::nodeFields($expected), self::nodeFields($actual), "offset $offset width $width");
                    $caseCount++;
                }
            }
        }
        self::assertSame(42, $caseCount);
    }
}
