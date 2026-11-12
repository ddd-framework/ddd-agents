<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionCover;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCoverToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization\CompactionCharacterizationFixtures;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization\CompactionCoverCountingNodesDouble;
use DDD\Tests\Agents\Support\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * {@see AIConversationCompactionCover::buildToolTelemetryBefore()} on the
 * scenarios of CompactionTelemetryMembershipAndCoverTelemetryCharacterizationTest: the two fields the briefing reads
 * (`countsByToolName`, order included, and `countsIncomplete`). Formerly the expected values came
 * from `SupervisorCheckpointService::collectCoverToolTelemetry()` run beside it; that method is deleted, its answers are
 * frozen in the rows. Container-free.
 */
class AIConversationCompactionCoverToolTelemetryDifferentialTest extends UnitTestCase
{
    use CompactionCharacterizationFixtures;

    /** @return array<string, array{0: string, 1: array<string, int>, 2: bool}> scenario, the counts the supervisor collected, its incomplete flag */
    public static function scenarioNames(): array
    {
        return [
            'counts sum by tool name across two nodes' => ['countsSumAcrossTwoNodes', ['updateProfile' => 6, 'getProfileSummary' => 1], false],
            'loaded rows and nodes past the checkpoint are skipped' => ['loadedRowsAndPastCheckpointSkipped', ['updateProfile' => 1], false],
            'a node starting at or before the checkpoint counts whole' => ['nodeStartingBeforeCheckpointCountsWhole', ['updateProfile' => 3], false],
            'no loaded rows' => ['noLoadedRows', ['updateProfile' => 2], false],
            'a partial overlap with the loaded rows' => ['partialOverlap', ['updateProfile' => 1], true],
            'a pre-slot node and a node without preserved facts' => ['preSlotNodes', ['updateProfile' => 1], true],
            'the node flags are ORed' => ['nodeFlagsOred', ['updateProfile' => 5], true],
            'a roll-up cover' => ['rollUpCover', ['updateProfile' => 2], false],
            'tool names in first-seen order across nodes' => ['toolNamesInFirstSeenOrder', ['getProfileSummary' => 2, 'updateProfile' => 2, 'addKeywords' => 1], false],
            'every node past the checkpoint' => ['everyNodePastCheckpoint', [], false],
        ];
    }

    #[DataProvider('scenarioNames')]
    /** @param array<string, int> $expectedCountsByToolName */
    public function testTheCoverTelemetryEqualsTheSupervisorCollection(string $scenarioName, array $expectedCountsByToolName, bool $expectedCountsIncomplete): void
    {
        [$nodes, $loadedMessages, $upToMessageIndex] = $this->buildScenario($scenarioName);
        $conversation = self::fixtureConversation();
        $conversation->compactionNodes = self::fixtureNodeSet($nodes, CompactionCoverCountingNodesDouble::class);

        $coverToolTelemetry = $conversation->compactionNodes->buildCover()->buildToolTelemetryBefore(
            $this->firstLoadedMessageIndex($loadedMessages),
            $upToMessageIndex
        );

        // assertSame on arrays compares the key order too
        self::assertSame($expectedCountsByToolName, $coverToolTelemetry->getCompletedCountsByToolName());
        self::assertSame($expectedCountsIncomplete, $coverToolTelemetry->countsIncomplete);
    }

    public function testAnEmptyCoverAnswersWhatTheSupervisorAnswersForAnUnloadedTree(): void
    {
        $supervisorTelemetry = self::fixtureInvoke($this->supervisorService(), 'buildCoverToolTelemetryBefore', [self::fixtureConversation(), PHP_INT_MAX, 99]);
        self::assertInstanceOf(AIConversationCoverToolTelemetry::class, $supervisorTelemetry);
        $coverToolTelemetry = AIConversationCompactionCover::ofCoverNodes([])->buildToolTelemetryBefore(PHP_INT_MAX, 99);

        self::assertSame([], $supervisorTelemetry->getCompletedCountsByToolName());
        self::assertFalse($supervisorTelemetry->countsIncomplete);
        self::assertSame($supervisorTelemetry->getCompletedCountsByToolName(), $coverToolTelemetry->getCompletedCountsByToolName());
        self::assertSame($supervisorTelemetry->countsIncomplete, $coverToolTelemetry->countsIncomplete);
    }

    public function testTheValueObjectSumsIntoTheEntryOfTheFirstMention(): void
    {
        $coverToolTelemetry = new AIConversationCoverToolTelemetry();
        $coverToolTelemetry->addCompletedCount('updateProfile', 2);
        $coverToolTelemetry->addCompletedCount('getProfileSummary', 1);
        $coverToolTelemetry->addCompletedCount('updateProfile', 3);

        self::assertSame(['updateProfile' => 5, 'getProfileSummary' => 1], $coverToolTelemetry->getCompletedCountsByToolName());
        self::assertCount(2, $coverToolTelemetry->toolCompletionCounts);
        self::assertFalse($coverToolTelemetry->countsIncomplete);
    }

    protected function supervisorService(): SupervisorCheckpointService
    {
        return self::fixtureInstantiate(SupervisorCheckpointService::class);
    }

    /** @param AIConversationMessage[] $loadedMessages the lowest loaded index (PHP_INT_MAX without a row) — the boundary the supervisor reads */
    protected function firstLoadedMessageIndex(array $loadedMessages): int
    {
        $loadedIndexes = array_map(static fn (AIConversationMessage $loadedMessage): int => (int)$loadedMessage->indexInConversation, $loadedMessages);
        return $loadedIndexes !== [] ? min($loadedIndexes) : PHP_INT_MAX;
    }

    /** @param int[] $updateProfileIndexes */
    protected static function updateProfileTelemetry(array $updateProfileIndexes): AIConversationCompactionToolTelemetry
    {
        return AIConversationCompactionToolTelemetry::fromMessages(array_map(
            static fn (int $index): AIConversationMessage => self::fixtureToolCallMessage($index, 'updateProfile', ['v' => $index], 'saved'),
            $updateProfileIndexes
        ));
    }

    /** @return array{0: AIConversationCompactionNode[], 1: AIConversationMessage[], 2: int} nodes, loaded messages, checkpoint index */
    protected function buildScenario(string $scenarioName): array
    {
        return match ($scenarioName) {
            'countsSumAcrossTwoNodes' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1, 2, 3])),
                    self::fixtureNodeWithTelemetry(10, 19, AIConversationCompactionToolTelemetry::fromMessages([
                        self::fixtureToolCallMessage(11, 'updateProfile', ['v' => 11], 'saved'),
                        self::fixtureToolCallMessage(12, 'updateProfile', ['v' => 12], 'saved'),
                        self::fixtureToolCallMessage(13, 'updateProfile', ['v' => 13], 'saved'),
                        self::fixtureToolCallMessage(14, 'getProfileSummary', ['shopId' => 7], 'profile'),
                    ])),
                ],
                [self::fixtureToolCallMessage(20, 'addKeywords')],
                99,
            ],
            'loadedRowsAndPastCheckpointSkipped' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11])),
                    self::fixtureNodeWithTelemetry(20, 29, self::updateProfileTelemetry([21])),
                ],
                [self::fixtureToolCallMessage(25, 'addKeywords'), self::fixtureToolCallMessage(20, 'addKeywords')],
                9,
            ],
            'nodeStartingBeforeCheckpointCountsWhole' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11, 15])),
                ],
                [],
                12,
            ],
            'noLoadedRows' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11])),
                ],
                [],
                99,
            ],
            'partialOverlap' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11, 17])),
                ],
                [self::fixtureToolCallMessage(15, 'addKeywords'), self::fixtureToolCallMessage(17, 'updateProfile')],
                99,
            ],
            'preSlotNodes' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, null),
                    self::fixtureNode(0, 10, 19),
                    self::fixtureNodeWithTelemetry(20, 29, self::updateProfileTelemetry([21])),
                ],
                [],
                99,
            ],
            'nodeFlagsOred' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1, 2, 3, 4])),
                    self::fixtureNodeWithTelemetry(10, 19, AIConversationCompactionToolTelemetry::merge([null, self::updateProfileTelemetry([11])])),
                ],
                [],
                99,
            ],
            'rollUpCover' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([1])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11])),
                    self::buildRollUpNode(),
                ],
                [],
                99,
            ],
            'toolNamesInFirstSeenOrder' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, AIConversationCompactionToolTelemetry::fromMessages([
                        self::fixtureToolCallMessage(1, 'getProfileSummary', ['shopId' => 7], 'profile'),
                        self::fixtureToolCallMessage(2, 'updateProfile', ['v' => 2], 'saved'),
                    ])),
                    self::fixtureNodeWithTelemetry(10, 19, AIConversationCompactionToolTelemetry::fromMessages([
                        self::fixtureToolCallMessage(11, 'addKeywords', ['k' => 11], 'added'),
                        self::fixtureToolCallMessage(12, 'updateProfile', ['v' => 12], 'saved'),
                        self::fixtureToolCallMessage(13, 'getProfileSummary', ['shopId' => 7], 'profile'),
                    ])),
                ],
                [self::fixtureToolCallMessage(30, 'addKeywords')],
                99,
            ],
            'everyNodePastCheckpoint' => [
                [
                    self::fixtureNodeWithTelemetry(0, 9, self::updateProfileTelemetry([6])),
                    self::fixtureNodeWithTelemetry(10, 19, self::updateProfileTelemetry([11])),
                ],
                [],
                -1,
            ],
            default => throw new InvalidArgumentException("Unknown scenario: {$scenarioName}"),
        };
    }

    protected static function buildRollUpNode(): AIConversationCompactionNode
    {
        $rollUp = self::fixtureNode(1, 0, 19);
        $rollUp->preservedFacts = new AIConversationCompactionPreservedFacts();
        $rollUp->preservedFacts->toolTelemetry = self::updateProfileTelemetry([1, 11]);
        return $rollUp;
    }
}
