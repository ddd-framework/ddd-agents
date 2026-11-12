<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Keys;

use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\TaskPlanItemArraysFixture;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use DDD\Presentation\Base\OpenApi\Document;
use Symfony\Component\Routing\RouteCollection;

/**
 * The server-minted item keys: the grammar, the minting at the seed and in today's full-replace parse
 * (key = number path), the persisted fields and their order, the round trip, the derivation for a plan stored without
 * keys (idempotent, render bytes unchanged) and the preservation of a stored KEYED tree whose keys are broken (kept as
 * stored, never repaired by position, every irregularity listed).
 */
class TaskPlanItemKeysTest extends UnitTestCase
{
    /** @return array<int, array<string, mixed>> the P0 holiday plan as `update_task_plan` items */
    protected static function p0Arguments(): array
    {
        return [
            ['text' => Holiday::ITEM_1, 'status' => 'open'],
            ['text' => Holiday::ITEM_2, 'status' => 'open', 'subtasks' => [
                ['text' => Holiday::ITEM_2_1, 'status' => 'open'],
                ['text' => Holiday::ITEM_2_2, 'status' => 'open'],
            ]],
            ['text' => Holiday::ITEM_3, 'status' => 'open', 'subtasks' => [
                ['text' => Holiday::ITEM_3_1, 'status' => 'open'],
                ['text' => Holiday::ITEM_3_2, 'status' => 'open'],
            ]],
        ];
    }

    protected static function parsedP0(): TaskPlan
    {
        return TaskPlanItemArraysFixture::plan(self::p0Arguments(), 0);
    }

    /** @return array<string, string> number path → key, over the whole tree in pre-order */
    protected static function keysByNumberPath(TaskPlan $taskPlan): array
    {
        $keysByNumberPath = [];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $keysByNumberPath[$taskPlanItemAddress->numberPath] = (string)$taskPlanItemAddress->item->key;
        }
        return $keysByNumberPath;
    }

    /** @return array<string, int> key → highestSubtaskKeyNumber */
    protected static function countersByKey(TaskPlan $taskPlan): array
    {
        $countersByKey = [];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $countersByKey[(string)$taskPlanItemAddress->item->key] = $taskPlanItemAddress->item->highestSubtaskKeyNumber;
        }
        return $countersByKey;
    }

    /**
     * The persisted tree without the identity fields — the shape every plan stored before the item keys existed has.
     *
     * @param array<int, array<string, mixed>> $repositoryItems
     * @return array<int, array<string, mixed>>
     */
    protected static function withoutIdentityFields(array $repositoryItems): array
    {
        foreach ($repositoryItems as $position => $itemFields) {
            unset($itemFields['key'], $itemFields['highestSubtaskKeyNumber'], $itemFields['seededByPurpose']);
            $itemFields['subtasks'] = self::withoutIdentityFields($itemFields['subtasks'] ?? []);
            $repositoryItems[$position] = $itemFields;
        }
        return $repositoryItems;
    }

    /**
     * One stored item with its key as given (null = no key stored).
     *
     * @param array<int, array<string, mixed>> $subtasks
     * @return array<string, mixed>
     */
    protected static function storedItem(string $text, ?string $key, array $subtasks = [], int $highestSubtaskKeyNumber = 0): array
    {
        $itemFields = ['text' => $text, 'status' => 'open', 'highestSubtaskKeyNumber' => $highestSubtaskKeyNumber, 'seededByPurpose' => false, 'subtasks' => $subtasks];
        if ($key !== null) {
            $itemFields['key'] = $key;
        }
        return $itemFields;
    }

    // ── the grammar ──

    public function testTheKeyGrammarTakesAnyNumberOfThreeDigitSegments(): void
    {
        self::assertSame('^[1-9][0-9]{0,2}(\.[1-9][0-9]{0,2})*$', TaskPlanItem::KEY_PATTERN);
        self::assertSame(999, TaskPlanItem::KEY_SEGMENT_MAX);
        foreach (['7', '7.2', '7.2.11', '999.1', '1.2.3.4.5.6.7'] as $conformingKey) {
            self::assertTrue(TaskPlanItem::isConformingKey($conformingKey), $conformingKey);
        }
        foreach (['', '0', '07.2', '7.0', '1000', '7.', '.7', '7..1', '7,2', ' 7', '7.2.11 Update Contact Details', '7.x'] as $malformedKey) {
            self::assertFalse(TaskPlanItem::isConformingKey($malformedKey), $malformedKey);
        }
        self::assertSame(11, TaskPlanItem::getLastKeySegment('7.2.11'));
        self::assertNull(TaskPlanItem::getLastKeySegment('7.x'));
    }

    // ── minting ──

    public function testTheSeedMintsThePositionsInPreOrderWithChildCountCountersAndMarksEveryItemSeeded(): void
    {
        $seededItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Act 1', 'subtasks' => ['Read the profile', 'Confirm the name', 'Read the profile']],
            'Act 2',
            '   ',
            ['text' => 'Act 3', 'doneRequiresToolCall' => 'addGoal', 'subtasks' => [['text' => 'Goal', 'subtasks' => ['Ask the owner']]]],
            'Act 2',
        ]);
        $seededPlan = new TaskPlan();
        $seededPlan->items = $seededItems;

        self::assertSame(['1' => '1', '1.1' => '1.1', '1.2' => '1.2', '2' => '2', '3' => '3', '3.1' => '3.1', '3.1.1' => '3.1.1'], self::keysByNumberPath($seededPlan), 'a blank text and a repeated text are skipped, as the set always collapsed them');
        self::assertSame(['1' => 2, '1.1' => 0, '1.2' => 0, '2' => 0, '3' => 1, '3.1' => 1, '3.1.1' => 0], self::countersByKey($seededPlan));
        foreach ($seededItems->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            self::assertTrue($taskPlanItemAddress->item->seededByPurpose, (string)$taskPlanItemAddress->item->key);
        }
        self::assertSame(3, $seededPlan->mapToRepository()['highestItemKeyNumber'], 'the seed sets no plan counter; the persisted one is never below the top-level keys');
    }

    public function testTodaysFullReplaceMintsAllNewKeysEqualToTheNumberPaths(): void
    {
        $parsedPlan = self::parsedP0();

        self::assertSame(['1' => '1', '2' => '2', '2.1' => '2.1', '2.2' => '2.2', '3' => '3', '3.1' => '3.1', '3.2' => '3.2'], self::keysByNumberPath($parsedPlan));
        self::assertSame(['1' => 0, '2' => 2, '2.1' => 0, '2.2' => 0, '3' => 2, '3.1' => 0, '3.2' => 0], self::countersByKey($parsedPlan));
        self::assertSame(3, $parsedPlan->highestItemKeyNumber);
        foreach ($parsedPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            self::assertFalse($taskPlanItemAddress->item->seededByPurpose, 'a parsed item is the model\'s');
        }
    }

    // ── the renders read the key ──

    public function testEveryRenderOfAPlanNeverRestructuredKeepsItsBytes(): void
    {
        $parsedPlan = self::parsedP0();
        self::assertSame(Holiday::p0()->toTailHintText(), $parsedPlan->toTailHintText(), 'the keyed plan renders the bytes of the keyless one');
        self::assertSame(Holiday::p0()->toTailHintText(true), $parsedPlan->toTailHintText(true));
        self::assertSame(Holiday::p0()->toJudgeText(), $parsedPlan->toJudgeText());
        self::assertSame(Holiday::p0()->toDecisionPlanText(), $parsedPlan->toDecisionPlanText());
        self::assertSame(Holiday::p0()->toFinishedDetailText(1), $parsedPlan->toFinishedDetailText(1));
        self::assertSame(Holiday::s1()->toTailHintText(), TaskPlan::fromRepositoryArray(Holiday::s1()->mapToRepository())->toTailHintText(), 'a stored keyless plan reads back with the same render');
    }

    public function testTheRenderPrintsTheKeyNotThePosition(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [
            self::storedItem('First', '1'),
            self::storedItem('Inserted later', '4'),
            self::storedItem('Second', '2', [self::storedItem('Child', '2.7')], 7),
        ], 'highestItemKeyNumber' => 4]);

        self::assertSame(
            ['[ ] 1 First ← next', '[ ] 4 Inserted later', '[ ] 2 Second', '  [ ] 2.7 Child'],
            $storedPlan->items->buildTailHintLines(),
            'the stored order walks, the key labels — nothing sorts by key'
        );
        self::assertNull($storedPlan->keyIntegrityViolations);
    }

    // ── persistence ──

    public function testTheIdentityFieldsPersistRightAfterTheTextAndThePlanCounterLast(): void
    {
        $repositoryFields = self::parsedP0()->items->first()->toRepositoryFields();
        self::assertSame(['text', 'key', 'highestSubtaskKeyNumber', 'seededByPurpose', 'ownerFacingTitle'], array_slice(array_keys($repositoryFields), 0, 5));
        $planSlice = self::parsedP0()->mapToRepository();
        self::assertSame('highestItemKeyNumber', array_key_last($planSlice));
        self::assertSame(3, $planSlice['highestItemKeyNumber']);
    }

    public function testAKeyedPlanRoundTripsByteIdenticallyWithoutViolations(): void
    {
        $seededPlan = new TaskPlan();
        $seededPlan->items = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Act 1', 'subtasks' => ['A', 'B']], 'Act 2']);
        $storedSlice = $seededPlan->mapToRepository();

        $readPlan = TaskPlan::fromRepositoryArray($storedSlice);

        self::assertSame($storedSlice, $readPlan->mapToRepository());
        self::assertSame($storedSlice, TaskPlan::fromRepositoryArray($readPlan->mapToRepository())->mapToRepository());
        self::assertNull($readPlan->keyIntegrityViolations);
        self::assertSame($seededPlan->toTailHintText(), $readPlan->toTailHintText());
    }

    public function testTheUniqueKeyIsTheKeySoTwoSiblingsWithOneTextStayTwoItems(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [self::storedItem('Same', '1'), self::storedItem('Same', '2')]]);

        self::assertSame(2, $storedPlan->items->count());
        self::assertSame(TaskPlanItem::uniqueKeyStatic('key:2'), $storedPlan->items->getElements()[1]->uniqueKey());
        $keylessItem = new TaskPlanItem();
        $keylessItem->text = 'In memory';
        self::assertSame(TaskPlanItem::uniqueKeyStatic('In memory'), $keylessItem->uniqueKey(), 'an item not minted yet keeps the text identity');
    }

    // ── a plan stored before keys: derived on read ──

    public function testAKeylessStoredPlanDerivesTheNumberPathsIdempotently(): void
    {
        $parsedPlan = self::parsedP0();
        $parsedPlan->items->getElements()[1]->subtasks->getElements()[1]->doneRequiresToolCall = 'replyToReview';
        $legacySlice = $parsedPlan->mapToRepository();
        $legacySlice['items'] = self::withoutIdentityFields($legacySlice['items']);
        unset($legacySlice['highestItemKeyNumber']);

        $derivedPlan = TaskPlan::fromRepositoryArray($legacySlice);

        self::assertSame(self::keysByNumberPath($parsedPlan), self::keysByNumberPath($derivedPlan));
        self::assertSame(self::countersByKey($parsedPlan), self::countersByKey($derivedPlan));
        self::assertSame(3, $derivedPlan->highestItemKeyNumber);
        self::assertNull($derivedPlan->keyIntegrityViolations, 'a keyless tree is no violation');
        self::assertTrue($derivedPlan->items->getElements()[1]->subtasks->getElements()[1]->seededByPurpose, 'a stored item with a declaration reads as seeded');
        self::assertFalse($derivedPlan->items->first()->seededByPurpose, 'one without reads as the model\'s');
        self::assertSame($parsedPlan->toTailHintText(), $derivedPlan->toTailHintText());
        $derivedSlice = $derivedPlan->mapToRepository();
        self::assertSame($derivedSlice, TaskPlan::fromRepositoryArray($legacySlice)->mapToRepository(), 'every read derives the same keys');
        self::assertSame($derivedSlice, TaskPlan::fromRepositoryArray($derivedSlice)->mapToRepository(), 'the first write persists them; the next read keeps them');
    }

    public function testAKeylessStoredPlanKeepsAStoredCounterAboveItsChildCount(): void
    {
        $derivedPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'A', 'status' => 'open', 'highestSubtaskKeyNumber' => 5, 'subtasks' => [['text' => 'A1', 'status' => 'open']]],
        ], 'highestItemKeyNumber' => 9]);

        self::assertSame(9, $derivedPlan->highestItemKeyNumber);
        self::assertSame(5, $derivedPlan->items->first()->highestSubtaskKeyNumber, 'counters never decrease');
        self::assertSame('1.1', $derivedPlan->items->first()->subtasks->first()->key);
    }

    // ── a keyed tree: preserved, never repaired by position ──

    public function testADuplicateKeyIsKeptOnBothItemsAndListed(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [
            self::storedItem('First', '1'),
            self::storedItem('Second', '2', [self::storedItem('Child', '2.1'), self::storedItem('Twin', '2.1')], 1),
        ], 'highestItemKeyNumber' => 2]);

        self::assertSame(['1' => '1', '2' => '2', '2.1' => '2.1', '2.2' => '2.1'], self::keysByNumberPath($storedPlan), 'both items stay, each with its stored key');
        self::assertSame(['"2.1" appears twice'], $storedPlan->keyIntegrityViolations->lines);
    }

    public function testAMalformedKeyIsKeptAndListed(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [self::storedItem('First', '1'), self::storedItem('Odd', '7.x')], 'highestItemKeyNumber' => 1]);

        self::assertSame(['1' => '1', '2' => '7.x'], self::keysByNumberPath($storedPlan));
        self::assertSame(['"7.x" is not a key'], $storedPlan->keyIntegrityViolations->lines);
        self::assertSame(1, $storedPlan->highestItemKeyNumber, 'a malformed key raises no counter');
    }

    public function testAKeyUnderTheWrongParentIsKeptAndListed(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [
            self::storedItem('First', '1'),
            self::storedItem('Second', '2', [self::storedItem('Stray', '3.1')]),
            self::storedItem('Deep at the top', '4.1'),
        ], 'highestItemKeyNumber' => 2]);

        self::assertSame(['1' => '1', '2' => '2', '2.1' => '3.1', '3' => '4.1'], self::keysByNumberPath($storedPlan));
        self::assertSame(['"4.1" sits at the top level', '"3.1" sits under "2"'], $storedPlan->keyIntegrityViolations->lines);
        self::assertSame(0, $storedPlan->items->getElements()[1]->highestSubtaskKeyNumber, 'a stray key raises no counter');
    }

    public function testAKeylessItemInAKeyedTreeGetsAFreshKeyFromItsParentsCounterNeverItsPosition(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [
            self::storedItem('First', '1', [self::storedItem('Child', '1.1'), self::storedItem('Keyless child', null)], 1),
            self::storedItem('Keyless', null),
            self::storedItem('Inserted', '4', [self::storedItem('Stray', '1.2')]),
        ], 'highestItemKeyNumber' => 4]);

        self::assertSame(['1' => '1', '1.1' => '1.1', '1.2' => '1.3', '2' => '5', '3' => '4', '3.1' => '1.2'], self::keysByNumberPath($storedPlan), 'position 2 gets 5 (not 2); the keyless child skips the 1.2 another item holds');
        self::assertSame(5, $storedPlan->highestItemKeyNumber);
        self::assertSame(3, $storedPlan->items->first()->highestSubtaskKeyNumber);
        self::assertSame(
            ['an item without a key at the top level got "5"', 'an item without a key under "1" got "1.3"', '"1.2" sits under "4"'],
            $storedPlan->keyIntegrityViolations->lines
        );
        self::assertSame(
            TaskPlan::fromRepositoryArray($storedPlan->mapToRepository())->mapToRepository(),
            $storedPlan->mapToRepository(),
            'once written, the fresh keys are stored keys'
        );
    }

    public function testTheViolationsAreNeverPersisted(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [self::storedItem('Odd', '7.x')]]);
        self::assertNotNull($storedPlan->keyIntegrityViolations);
        self::assertArrayNotHasKey('keyIntegrityViolations', $storedPlan->mapToRepository());
    }

    public function testTheViolationsReachNoApiPayloadOnlyTheAdminProjectionsCarryThem(): void
    {
        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [self::storedItem('Odd', '7.x')]]);
        $persistedBeforeSerialization = $storedPlan->mapToRepository();

        $apiPayload = (array)$storedPlan->toObject(cached: false);

        self::assertArrayNotHasKey('keyIntegrityViolations', $apiPayload, '#[HideProperty] keeps the lines off every API surface');
        self::assertArrayHasKey('items', $apiPayload);
        self::assertSame(['"7.x" is not a key'], $storedPlan->keyIntegrityViolations->lines, 'the hide changes the payload, never the plan');
        self::assertSame($persistedBeforeSerialization, $storedPlan->mapToRepository(), 'the hide touches no persisted field');
    }

    public function testTheViolationsLeaveTheOpenApiSchemaWhileThePersistedPlanStaysAsItWas(): void
    {
        // the schema tree reads the Document singleton for every nested class, as on the documentation endpoint
        new Document(new RouteCollection(), maxRecursiveSchemaDepth: 2);
        $schema = new Schema(new ClassWithNamespace(TaskPlan::class), Parameter::RESPONSE);
        $schema->buildSchema();
        $documentedPropertyNames = is_object($schema->properties) ? [] : array_keys($schema->properties);

        self::assertContains('items', $documentedPropertyNames);
        self::assertNotContains('keyIntegrityViolations', $documentedPropertyNames, '#[Ignore] keeps the field out of the OpenAPI schema and the SDK');

        $storedPlan = TaskPlan::fromRepositoryArray(['items' => [self::storedItem('Odd', '7.x')], 'highestItemKeyNumber' => 3, 'title' => 'Plan']);
        self::assertSame(
            ['items', 'updatedAtTurnInitiatorMessageIndex', 'resolvedAtTurnInitiatorMessageIndex', 'title', 'createdAtMessageIndex', 'completedAtMessageIndex', 'completedAt', 'outcomeSummary', 'platformClosedAtTurnEndNote', 'highestItemKeyNumber'],
            array_keys($storedPlan->mapToRepository()),
            'the plan column is written through mapToRepository() alone — the schema attribute changes none of its keys'
        );
        self::assertSame('7.x', $storedPlan->mapToRepository()['items'][0]['key']);
        self::assertSame(3, $storedPlan->mapToRepository()['highestItemKeyNumber']);
        self::assertSame('Plan', $storedPlan->mapToRepository()['title']);
        self::assertSame(
            ['"7.x" is not a key'],
            TaskPlan::fromRepositoryArray($storedPlan->mapToRepository())->keyIntegrityViolations->lines,
            'a re-read of the written plan finds the same lines'
        );
    }
}
