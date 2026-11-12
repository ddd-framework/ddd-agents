<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization\CompactionContainerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The roll-up fan-in is ONE rule for the threshold round and the completion pass
 * ({@see AIConversation::getChildNodesPerCompactionRollUp()}, formerly the compaction service's
 * `resolveRollUpFanIn()`): on a degenerate budget the per-conversation
 * {@see CompactionSettings::$tierFanIn} override, else {@see CompactionSettings::DEFAULT_TIER_FAN_IN};
 * on a tileable budget {@see CompactionBudgets::$rollupFanIn}. The completion pass used to take the default on a
 * degenerate budget whatever the override said.
 *
 * The conversation is transient (no id): the round keeps its tree in memory, the summarizer is a fixed-gist double.
 * Every message carries a model-counted footprint, so each message closes exactly one leaf.
 */
class CompactionRollUpFanInTest extends CompactionContainerTestCase
{
    /** A degenerate budget: 1 000 trigger tokens leave 200 history tokens after overhead (300) and reserve (100). */
    protected const int DEGENERATE_TRIGGER_TOKENS = 1000;

    /** A tileable budget: 100 000 trigger tokens, leaf span 4 500 tokens. */
    protected const int TILEABLE_TRIGGER_TOKENS = 100000;

    /** @return array<string, array{0: int|null, 1: bool, 2: int}> tierFanIn, degenerate, the fan-in the rule answers */
    public static function fanInCases(): array
    {
        return [
            'degenerate budget, tierFanIn 2' => [2, true, 2],
            'degenerate budget, no tierFanIn' => [null, true, CompactionSettings::DEFAULT_TIER_FAN_IN],
            'tileable budget, tierFanIn 2 ignored' => [2, false, CompactionBudgets::ROLLUP_FAN_IN],
            'tileable budget, no tierFanIn' => [null, false, CompactionBudgets::ROLLUP_FAN_IN],
        ];
    }

    #[DataProvider('fanInCases')]
    public function testFanInRuleFollowsTheBudgetAndTheOverride(?int $tierFanIn, bool $degenerate, int $expectedFanIn): void
    {
        $conversation = $this->buildConversation($tierFanIn, $degenerate ? self::DEGENERATE_TRIGGER_TOKENS : self::TILEABLE_TRIGGER_TOKENS, 8, 300);
        $budgets = $conversation->buildCompactionBudgets(AIConversationCompactionService::DEFAULT_EFFECTIVE_CONTEXT_TOKENS);
        $this->assertSame($degenerate, $budgets->isDegenerate);

        $this->assertSame($expectedFanIn, $conversation->getChildNodesPerCompactionRollUp($budgets));
    }

    public function testCompletionPassOnDegenerateBudgetRollsUpInGroupsOfTheTierFanIn(): void
    {
        $conversation = $this->buildConversation(2, self::DEGENERATE_TRIGGER_TOKENS, 8, 300);

        $nodesLanded = (new FixedSummaryCompactionServiceDouble())->compactConversationToCompletion($conversation);

        // 8 leaves → 4 → 2 → 1: groups of two up to the root
        $this->assertSame(15, $nodesLanded);
        $this->assertSame([8, 4, 2, 1], $this->countNodesPerLevel($conversation, 4));
        $this->assertSame([[3, 0, 7]], self::fixtureShape($this->nodesAtLevel($conversation, 3)));
    }

    public function testCompletionPassOnDegenerateBudgetWithoutTierFanInRollsUpInGroupsOfFour(): void
    {
        $conversation = $this->buildConversation(null, self::DEGENERATE_TRIGGER_TOKENS, 8, 300);

        $nodesLanded = (new FixedSummaryCompactionServiceDouble())->compactConversationToCompletion($conversation);

        // 8 leaves → 2 groups of four; the two level-1 nodes stay below a group of four
        $this->assertSame(10, $nodesLanded);
        $this->assertSame([8, 2, 0], $this->countNodesPerLevel($conversation, 3));
        $this->assertSame([[1, 0, 3], [1, 4, 7]], self::fixtureShape($this->nodesAtLevel($conversation, 1)));
    }

    public function testCompletionPassOnTileableBudgetRollsUpInGroupsOfTheBudgetFanInWhateverTheTierFanIn(): void
    {
        $conversation = $this->buildConversation(2, self::TILEABLE_TRIGGER_TOKENS, 25, 5000);
        $this->assertFalse($conversation->buildCompactionBudgets(AIConversationCompactionService::DEFAULT_EFFECTIVE_CONTEXT_TOKENS)->isDegenerate);

        $nodesLanded = (new FixedSummaryCompactionServiceDouble())->compactConversationToCompletion($conversation);

        // 25 leaves → 5 → 1: the budget's fan-in of five, the tierFanIn of two is the degenerate fallback only
        $this->assertSame(31, $nodesLanded);
        $this->assertSame([25, 5, 1, 0], $this->countNodesPerLevel($conversation, 4));
    }

    /**
     * A transient conversation of $messageCount assistant rows, each adding $tokensPerMessage to the model-counted
     * prompt footprint, with the compaction trigger and the tierFanIn override set.
     */
    protected function buildConversation(?int $tierFanIn, int $triggerTokens, int $messageCount, int $tokensPerMessage): AIConversation
    {
        $compactionSettings = new CompactionSettings();
        $compactionSettings->triggerTokens = $triggerTokens;
        $compactionSettings->tierFanIn = $tierFanIn;
        $messages = [];
        for ($index = 0; $index < $messageCount; $index++) {
            $message = self::fixtureAssistantMessage($index, $tokensPerMessage * ($index + 1));
            $message->outputTokens = $tokensPerMessage;
            $messages[] = $message;
        }
        $conversation = self::fixtureConversation();
        $conversation->compactionSettings = $compactionSettings;
        $conversation->messages = self::fixtureMessageSet($messages);
        return $conversation;
    }

    /** @return int[] the node count of each level 0 … $levelCount - 1 */
    protected function countNodesPerLevel(AIConversation $conversation, int $levelCount): array
    {
        $nodeCounts = [];
        for ($level = 0; $level < $levelCount; $level++) {
            $nodeCounts[] = $conversation->compactionNodes->countNodesAtLevel($level);
        }
        return $nodeCounts;
    }

    /** @return \DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode[] the nodes at $level, coveredFrom ASC */
    protected function nodesAtLevel(AIConversation $conversation, int $level): array
    {
        $nodes = array_values(array_filter(
            $conversation->compactionNodes->getElements(),
            static fn ($node): bool => $node->level === $level
        ));
        usort($nodes, static fn ($a, $b): int => $a->coveredFromMessageIndex <=> $b->coveredFromMessageIndex);
        return $nodes;
    }
}
