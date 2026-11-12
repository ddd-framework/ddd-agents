<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * {@see AIConversation::buildCompactionRoundSettings()} and
 * {@see AIConversation::getChildNodesPerCompactionRollUp()} against the round shape the compaction service computed
 * inline before the extraction (`runCompactionTreeRound()` and `resolveRollUpFanIn()`, copied below verbatim with the former
 * service constants). Compared over a grid of thresholds × triggers × debug leaf spans × message-count knobs ×
 * conversations (footprints, a trailing owner message, an existing tree): the budget fields, the footprint per
 * message, every leaf range, the roll-up width and the per-level retention.
 */
class CompactionRoundSettingsDifferentialTest extends UnitTestCase
{
    use CompactionCharacterizationFixtures;

    // the service's constants before b4, verbatim
    protected const int FORMER_DEFAULT_KEEP_RECENT_MESSAGES = 12;
    protected const int FORMER_DEFAULT_SEGMENT_MESSAGES = 20;
    protected const int FORMER_DEFAULT_TIER_FAN_IN = 4;
    protected const int FORMER_DEFAULT_LEVEL_RETENTION = 2;

    /** `resolveRollUpFanIn()` before b4, verbatim. */
    protected static function referenceFanIn(AIConversation $conversation, CompactionBudgets $budgets): int
    {
        if (!$budgets->isDegenerate) {
            return $budgets->rollupFanIn;
        }
        return $conversation->compactionSettings->tierFanIn ?? self::FORMER_DEFAULT_TIER_FAN_IN;
    }

    /** The shape part of `runCompactionTreeRound()` before b4, verbatim, as a comparable array. */
    protected static function referenceRoundShape(AIConversation $conversation, int $effectiveContextTokens): array
    {
        $settings = $conversation->compactionSettings ?? new CompactionSettings();
        $budgets = $conversation->buildCompactionBudgets($effectiveContextTokens);
        $fanIn = self::referenceFanIn($conversation, $budgets);
        if ($budgets->isDegenerate) {
            $budgets = null;
        }
        $keepRecentMessages = $settings->keepRecentMessages ?? self::FORMER_DEFAULT_KEEP_RECENT_MESSAGES;
        $segmentMessages = $settings->segmentMessages ?? self::FORMER_DEFAULT_SEGMENT_MESSAGES;
        $levelRetention = $budgets !== null ? $budgets->fineNodesPerLevel : ($settings->levelRetention ?? self::FORMER_DEFAULT_LEVEL_RETENTION);
        $footprints = $budgets !== null ? $conversation->buildMessageFootprints() : null;
        $leafRanges = $budgets !== null
            ? $conversation->buildPendingLeafRanges($budgets, $footprints)
            : $conversation->buildPendingLeafRangesByMessageCount($keepRecentMessages, $segmentMessages);
        return self::shape($budgets, $footprints, $leafRanges, $fanIn, $levelRetention, $conversation);
    }

    protected static function shape(?CompactionBudgets $budgets, mixed $footprints, mixed $leafRanges, int $fanIn, int $levelRetention, AIConversation $conversation): array
    {
        $footprintPerIndex = [];
        if ($footprints !== null) {
            foreach ($conversation->messages->getElements() as $message) {
                $footprintPerIndex[$message->indexInConversation] = $footprints->getTokensAtIndex((int)$message->indexInConversation);
            }
        }
        $ranges = [];
        foreach ($leafRanges as $leafRange) {
            $ranges[] = [$leafRange->fromIndex, $leafRange->toIndex];
        }
        return [
            'budgets' => $budgets === null ? null : get_object_vars($budgets),
            'footprints' => $footprints === null ? null : $footprintPerIndex,
            'leafRanges' => $ranges,
            'fanIn' => $fanIn,
            'levelRetention' => $levelRetention,
        ];
    }

    /** @return AIConversationMessage[] */
    protected static function rows(int $rowCount, int $tokensPerStep, bool $trailingOwnerMessage): array
    {
        $rows = [];
        for ($index = 0; $index < $rowCount; $index++) {
            $row = self::fixtureAssistantMessage($index, $index % 3 === 2 ? null : 1500 + $index * $tokensPerStep);
            $row->outputTokens = 30;
            $rows[] = $row;
        }
        if ($trailingOwnerMessage) {
            $ownerRow = self::fixtureAssistantMessage($rowCount);
            $ownerRow->role = AIConversationMessage::ROLE_USER;
            $ownerRow->authorType = AIConversationMessage::AUTHOR_USER;
            $ownerRow->message = 'and now?';
            $rows[] = $ownerRow;
        }
        return $rows;
    }

    public function testTheRoundSettingsEqualTheFormerInlineShape(): void
    {
        $settingsVariants = ['no settings' => null];
        foreach ([null, 0, 1000, 5000, 20000, 150000] as $triggerTokens) {
            foreach ([null, 400] as $debugLeafSegmentTokensOverride) {
                foreach ([[null, null, null, null], [3, 4, 2, 1], [0, 1, 1, 0]] as [$keepRecent, $segment, $tierFanIn, $levelRetention]) {
                    $compactionSettings = new CompactionSettings();
                    $compactionSettings->triggerTokens = $triggerTokens;
                    $compactionSettings->debugLeafSegmentTokensOverride = $debugLeafSegmentTokensOverride;
                    $compactionSettings->keepRecentMessages = $keepRecent;
                    $compactionSettings->segmentMessages = $segment;
                    $compactionSettings->tierFanIn = $tierFanIn;
                    $compactionSettings->levelRetention = $levelRetention;
                    $settingsVariants['trigger ' . ($triggerTokens ?? 'none') . ' leaf ' . ($debugLeafSegmentTokensOverride ?? 'none') . ' knobs ' . json_encode([$keepRecent, $segment, $tierFanIn, $levelRetention])] = $compactionSettings;
                }
            }
        }
        $caseCount = 0;
        foreach ($settingsVariants as $settingsLabel => $compactionSettings) {
            foreach ([0 => [10, 300, false], 1 => [60, 900, false], 2 => [60, 900, true], 3 => [120, 200, false]] as $conversationVariant => [$rowCount, $tokensPerStep, $trailingOwnerMessage]) {
                foreach ([false, true] as $withExistingTree) {
                    foreach ([3000, 25600, 100000] as $effectiveContextTokens) {
                        $conversation = self::fixtureConversation();
                        $conversation->messages = self::fixtureMessageSet(self::rows($rowCount, $tokensPerStep, $trailingOwnerMessage));
                        if ($compactionSettings !== null) {
                            $conversation->compactionSettings = $compactionSettings;
                        }
                        if ($withExistingTree) {
                            $conversation->compactionNodes = self::fixtureNodeSet([self::fixtureNode(0, 0, 3), self::fixtureNode(0, 4, 6)]);
                        }
                        $expected = self::referenceRoundShape($conversation, $effectiveContextTokens);
                        $compactionRoundSettings = $conversation->buildCompactionRoundSettings($effectiveContextTokens);
                        $actual = self::shape(
                            $compactionRoundSettings->compactionBudgets,
                            $compactionRoundSettings->messageFootprints,
                            $compactionRoundSettings->leafRanges,
                            $compactionRoundSettings->childNodesPerRollUp,
                            $compactionRoundSettings->retainedExposedNodesPerLevel,
                            $conversation
                        );
                        self::assertSame($expected, $actual, "$settingsLabel / conversation $conversationVariant / tree " . (int)$withExistingTree . " / $effectiveContextTokens");
                        $budgets = $conversation->buildCompactionBudgets($effectiveContextTokens);
                        self::assertSame(self::referenceFanIn($conversation, $budgets), $conversation->getChildNodesPerCompactionRollUp($budgets));
                        $caseCount++;
                    }
                }
            }
        }
        self::assertSame(37 * 4 * 2 * 3, $caseCount);
        self::assertSame(
            [self::FORMER_DEFAULT_KEEP_RECENT_MESSAGES, self::FORMER_DEFAULT_SEGMENT_MESSAGES, self::FORMER_DEFAULT_TIER_FAN_IN, self::FORMER_DEFAULT_LEVEL_RETENTION],
            [CompactionSettings::DEFAULT_KEEP_RECENT_MESSAGES, CompactionSettings::DEFAULT_SEGMENT_MESSAGES, CompactionSettings::DEFAULT_TIER_FAN_IN, CompactionSettings::DEFAULT_LEVEL_RETENTION],
            'the defaults moved unchanged'
        );
    }
}
