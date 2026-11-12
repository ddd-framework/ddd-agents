<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Keys;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateOutcome;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The use-case catalogue of the keyed update, one test per row F1–F37 (F7 / F21 / F22 / F23 / F24 run through the live
 * dispatch and the replay: {@see \DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanCallOutcome\UpdateTaskPlanKeyedDispatchTest}
 * and {@see \DDD\Tests\Agents\Domain\AI\Services\Conversations\TaskPlanLiveReplayParityTest}). Each row sends the request of the
 * catalogue to the reference plan S1 (1 done, 2.1 done, the rest open) and pins the exact result, the resulting
 * tree by key and the counters; every rejected row also asserts that the standing plan is byte-identical afterwards
 * ({@see assertRejectedWithLines()}).
 */
class UpdateTaskPlanKeyedCatalogueTest extends KeyedTaskPlanUpdateTestCase
{
    public function testF1TheFirstPlanMintsTheNumberPathsAsKeys(): void
    {
        $taskPlanUpdateOutcome = $this->create([
            ['text' => Holiday::ITEM_1],
            ['text' => Holiday::ITEM_2, 'subtasks' => [['text' => Holiday::ITEM_2_1], ['text' => Holiday::ITEM_2_2]]],
            ['text' => Holiday::ITEM_3, 'subtasks' => [['text' => Holiday::ITEM_3_1], ['text' => Holiday::ITEM_3_2]]],
        ]);
        $createdPlan = $this->appliedPlan($taskPlanUpdateOutcome);

        self::assertTrue($taskPlanUpdateOutcome->isCreation);
        self::assertSame('Task plan updated: 7 items (0 done, 7 open, 0 blocked).', $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame(['1', '2', '2.1', '2.2', '3', '3.1', '3.2'], array_map('strval', array_keys(self::treeByKey($createdPlan))));
        self::assertSame(['plan' => 3, '1' => 0, '2' => 2, '2.1' => 0, '2.2' => 0, '3' => 2, '3.1' => 0, '3.2' => 0], self::countersOf($createdPlan));
        self::assertSame(Holiday::p0()->buildDeepCopy()->items->buildTailHintLines(), $createdPlan->items->buildTailHintLines());
        self::assertSame('[ ] 1 Set the holiday hours (24–26 Dec closed) ← next', $createdPlan->items->buildTailHintLines()[0]);
        self::assertSame(0, $createdPlan->updatedAtTurnInitiatorMessageIndex);
    }

    public function testF2ATopLevelItemIsAddedAtTheEndAndEveryStandingItemKeepsItsState(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail the owner a short summary']]));

        self::assertSame("Task plan updated: 8 items (2 done, 6 open, 0 blocked). Added: 4 'Mail the owner a short summary' (at the end).", $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame(['1' => '[done] Set the holiday hours (24–26 Dec closed) — closed 24–26 Dec saved', '2' => '[open] Answer the newest reviews',
            '2.1' => '[done] Read the newest reviews — 3 new, 1 negative', '2.2' => '[open] Draft and post the replies', '3' => '[open] Write the holiday post',
            '3.1' => '[open] Draft the post', '3.2' => "[open] Publish it after the owner's approval", '4' => '[open] Mail the owner a short summary'], self::treeByKey($changedPlan));
        self::assertSame(4, $changedPlan->getHighestItemKeyNumber());
    }

    public function testF3ASubtaskInsertedBetweenTwoSiblingsGetsTheNextNumberAndStandsWhereItWasSent(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['text' => 'Thank the 5-star reviewers by name'], ['key' => '2.2']]],
            ['key' => '3'],
        ]));

        self::assertSame(
            "Task plan updated: 8 items (2 done, 6 open, 0 blocked). Added: 2.3 'Thank the 5-star reviewers by name' (after 2.1). " . TaskPlanUpdateOutcome::KEYS_NEVER_CHANGE_SENTENCE,
            $taskPlanUpdateOutcome->renderAppliedSummary()
        );
        $tailHintLines = $changedPlan->items->buildTailHintLines();
        self::assertSame('  [✓] 2.1 Read the newest reviews — 3 new, 1 negative', $tailHintLines[2]);
        self::assertSame('  [ ] 2.3 Thank the 5-star reviewers by name ← next', $tailHintLines[3]);
        self::assertSame('  [ ] 2.2 Draft and post the replies', $tailHintLines[4]);
        self::assertSame(3, $changedPlan->items->getItemByKey('2')->highestSubtaskKeyNumber);
    }

    public function testF4ASubtaskUnderADoneLeafKeepsTheLeafsStatusAndOpensItAsAContainer(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [['key' => '1', 'subtasks' => [['text' => 'Close on 31 Dec too']]], ['key' => '2'], ['key' => '3']]));

        self::assertSame("Task plan updated: 8 items (1 done, 7 open, 0 blocked). Added: 1.1 'Close on 31 Dec too' (first under 1).", $taskPlanUpdateOutcome->renderAppliedSummary());
        $doneLeafTurnedContainer = $changedPlan->items->getItemByKey('1');
        self::assertSame(TaskPlanItem::STATUS_DONE, $doneLeafTurnedContainer->status);
        self::assertSame('closed 24–26 Dec saved', $doneLeafTurnedContainer->statusNote);
        // it renders open; its kept done note stays off the open line — only an open item's note is a retry obstacle
        self::assertSame('[ ] 1 Set the holiday hours (24–26 Dec closed)', $changedPlan->items->buildTailHintLines()[0]);
        self::assertSame(1, $doneLeafTurnedContainer->highestSubtaskKeyNumber);
    }

    public function testF5SeveralItemsAreMintedInPreOrder(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [
            ['key' => '1'],
            ['key' => '2'],
            ['key' => '3', 'subtasks' => [['key' => '3.1'], ['key' => '3.2'], ['text' => 'Post it on Facebook too']]],
            ['text' => 'Mail the owner a summary', 'subtasks' => [['text' => 'Draft it'], ['text' => 'Send it']]],
        ]));

        self::assertSame(
            "Task plan updated: 11 items (2 done, 9 open, 0 blocked). Added: 3.3 'Post it on Facebook too' (after 3.2), 4 'Mail the owner a summary' (at the end),"
                . " 4.1 'Draft it' (first under 4), 4.2 'Send it' (after 4.1).",
            $taskPlanUpdateOutcome->renderAppliedSummary()
        );
        self::assertSame(['plan' => 4, '1' => 0, '2' => 2, '2.1' => 0, '2.2' => 0, '3' => 3, '3.1' => 0, '3.2' => 0, '3.3' => 0, '4' => 2, '4.1' => 0, '4.2' => 0], self::countersOf($changedPlan));
    }

    public function testF6ADroppedItemLeavesWithItsSubtreeAndItsNumberIsNeverReused(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => true]]));

        self::assertSame("Task plan updated: 4 items (2 done, 2 open, 0 blocked). Dropped: 3 'Write the holiday post' with its subtasks 3.1, 3.2.", $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame(3, $changedPlan->getHighestItemKeyNumber(), 'a counter never decreases');
        $laterOutcome = $this->update($changedPlan, [['key' => '1'], ['key' => '2'], ['text' => 'Mail the owner a summary']]);
        self::assertSame("Task plan updated: 5 items (2 done, 3 open, 0 blocked). Added: 4 'Mail the owner a summary' (at the end).", $laterOutcome->renderAppliedSummary());
    }

    public function testF7ASeededItemCannotBeDroppedUnderTheSeededProtections(): void
    {
        // unreachable through the dispatch: it refuses every update_task_plan of a seeded purpose first (F23); the
        // protection is tested on its own
        $seededPlan = self::seededS1();
        $this->assertRejectedWithLines($seededPlan, [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => [['key' => '3.1'], ['key' => '3.2', 'remove' => true]]]], [
            '"3.2" is part of the seeded plan and cannot be dropped — close it with set_task_status: not_applicable and the reason it does not apply.',
        ]);
    }

    public function testF8AReorderMovesTheStoredItemsWithEveryDeclarationAndReportsNothingReopened(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1Gated(), [['key' => '1'], ['key' => '3'], ['key' => '2']]));

        self::assertSame('Task plan updated: 7 items (2 done, 5 open, 0 blocked).', $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame([], $taskPlanUpdateOutcome->feedback, 'no REOPENED line (K0 reported one for a leaf that was never done)');
        self::assertSame(['1', '3', '3.1', '3.2', '2', '2.1', '2.2'], array_map('strval', array_keys(self::treeByKey($changedPlan))));
        self::assertSame('replyToReview', $changedPlan->items->getItemByKey('2.2')->doneRequiresToolCall);
        self::assertSame('post.drafted', $changedPlan->items->getItemByKey('3.1')->doneCriterion);
        self::assertSame('publishPost', $changedPlan->items->getItemByKey('3.2')->doneRequiresToolCall);
        self::assertSame(TaskPlanItem::STATUS_DONE, $changedPlan->items->getItemByKey('2.1')->status);
        self::assertSame('3.1', $changedPlan->items->getNextMarkedItem()->key, '← next follows the stored order');
    }

    public function testF9AnItemSentUnderAnotherParentIsRejectedAsAMove(): void
    {
        $this->assertRejectedWithLines(self::s1(), [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['key' => '3.2']]],
            ['key' => '3', 'subtasks' => [['key' => '3.1']]],
        ], [
            'Item "3.2" was sent under "2", but it belongs under "3". An item never moves: keep "3.2" where it is; to do this work elsewhere, drop it ({"key": "3.2", "remove": true}) and add a new item there. To keep it, name it inside its parent: {"key": "3", "subtasks": [{"key": "3.1"}, {"key": "3.2"}]} — a new item under "3" goes into that same list as {"text": "…"}.',
        ]);
    }

    public function testF10AStatusSentOnAKeptItemTransitionsItThroughTheTickApplication(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), self::f10Items()));

        self::assertSame('Task plan updated: 7 items (4 done, 3 open, 0 blocked).', $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame('[done] Draft and post the replies — 3 replies posted', self::treeByKey($changedPlan)['2.2']);
        self::assertSame('[done] Set the holiday hours (24–26 Dec closed) — closed 24–26 Dec saved', self::treeByKey($changedPlan)['1'], 'sent without status: unchanged');
        self::assertSame(0, $changedPlan->resolvedAtTurnInitiatorMessageIndex);
    }

    public function testF10AGatedStatusIsNotedByTheTicksGateAndReadsAsTheTickReads(): void
    {
        // the gated S1 — the done needs a completed replyToReview call; the tick's G13 NOTES it and keeps the
        // stored status (nothing of the tick is kept): the resend does exactly what set_task_status does
        $taskPlanUpdateOutcome = $this->update(self::s1Gated(), self::f10Items());
        $tickedPlan = self::s1Gated();
        $tickResult = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done', 'statusNote' => '3 replies posted']]], $tickedPlan, 0, $this->ownerTurnContext()->taskPlanEvidence->conversationMessages);

        self::assertSame($tickResult['feedback'], $taskPlanUpdateOutcome->feedback, 'one expectation set: the tick\'s lines');
        self::assertStringStartsWith("Task '2.2': done not applied — this item closes from the record of a completed `replyToReview` call", $taskPlanUpdateOutcome->feedback[0]);
        self::assertTrue($taskPlanUpdateOutcome->isUnchanged, 'the plan stays as it is');
        self::assertTrue($taskPlanUpdateOutcome->hasGateRefusedStatus);
        self::assertSame(self::s1Gated()->items->toRepositoryArray(), $tickedPlan->items->toRepositoryArray(), 'the tick changes no item field either');
    }

    public function testF10ASeededItemsDoneAppliesAsTheTickAppliesIt(): void
    {
        // no judgement park — a seeded item without a record gate takes the done as set_task_status does
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::seededS1(), self::f10Items()));

        self::assertSame([], $taskPlanUpdateOutcome->feedback);
        self::assertSame('[done] Draft and post the replies — 3 replies posted', self::treeByKey($changedPlan)['2.2']);
        self::assertSame(0, $changedPlan->resolvedAtTurnInitiatorMessageIndex, 'a standing item resolved is progress, as on the tick');
    }

    public function testAnAddedDoneItemAndTheStandingItemsDoneAreBothStoredDone(): void
    {
        $items = self::f10Items();
        $items[] = ['text' => 'Pin the holiday post', 'status' => 'done', 'statusNote' => 'pinned'];

        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::seededS1(), $items));

        self::assertSame([], $taskPlanUpdateOutcome->feedback);
        self::assertSame('[done] Draft and post the replies — 3 replies posted', self::treeByKey($changedPlan)['2.2']);
        self::assertSame('[done] Pin the holiday post — pinned', self::treeByKey($changedPlan)['4']);
    }

    public function testAPlanCreatedWithTwoOfThreeItemsDoneStoresThemDone(): void
    {
        // every item of a creation is added by the call — the plan made after the work keeps it
        $taskPlanUpdateContext = $this->ownerTurnContext();

        $createdPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->create([
            ['text' => 'Set the holiday hours', 'status' => 'done', 'statusNote' => 'closed 24–26 Dec saved'],
            ['text' => 'Answer the newest reviews', 'status' => 'done', 'statusNote' => '3 replies posted'],
            ['text' => 'Write the holiday post'],
        ], $taskPlanUpdateContext));

        self::assertSame([], $taskPlanUpdateOutcome->feedback);
        self::assertSame('Task plan updated: 3 items (2 done, 1 open, 0 blocked).', $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame('[done] Answer the newest reviews — 3 replies posted', self::treeByKey($createdPlan)['2']);
        self::assertSame('[open] Write the holiday post', self::treeByKey($createdPlan)['3']);
        self::assertNull($createdPlan->resolvedAtTurnInitiatorMessageIndex, 'a created-terminal item is a declaration, not progress');
    }

    public function testF10cAModelCreatedItemsDoneAppliesAtOnceAsTheTickAppliesIt(): void
    {
        // on an item the MODEL created the done applies, exactly as set_task_status applies it
        $taskPlanUpdateContext = $this->ownerTurnContext();

        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), self::f10Items(), $taskPlanUpdateContext));

        self::assertSame([], $taskPlanUpdateOutcome->feedback);
        self::assertSame('[done] Draft and post the replies — 3 replies posted', self::treeByKey($changedPlan)['2.2']);
    }

    /** @return array<int, array<string, mixed>> F10's call: every item kept, 2.2 sent done with its note */
    protected static function f10Items(): array
    {
        return [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2', 'status' => 'done', 'statusNote' => '3 replies posted']]],
            ['key' => '3'],
        ];
    }

    public function testF10bATransitionOnAnItemThePlatformResolvedClearsTheProvenance(): void
    {
        $standingPlan = self::s1();
        $platformClosedItem = $standingPlan->items->getItemByKey('1');
        $platformClosedItem->platformResolvedAtMessageIndex = 4;
        $platformClosedItem->preResolvedAtSeed = true;

        $sameStatusOutcome = $this->update($standingPlan, [['key' => '1', 'status' => 'done'], ['key' => '2'], ['key' => '3']]);
        self::assertTrue($sameStatusOutcome->isUnchanged, 'the stored status sent again is no transition: the provenance stays');

        $reopenedItem = $this->appliedPlan($this->update($standingPlan, [['key' => '1', 'status' => 'open'], ['key' => '2'], ['key' => '3']]))->items->getItemByKey('1');
        self::assertSame(TaskPlanItem::STATUS_OPEN, $reopenedItem->status);
        self::assertNull($reopenedItem->statusNote);
        self::assertNull($reopenedItem->platformResolvedAtMessageIndex);
        self::assertFalse($reopenedItem->preResolvedAtSeed);
    }

    public function testF11AChangedTextIsRejectedAndTheReplacementIsADropAndANewItem(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'text' => 'Write two holiday posts']], [
            'Item "3" reads \'Write the holiday post\'; you sent \'Write two holiday posts\'. An item\'s text is fixed — send {"key": "3"} without text. To change what the item asks for, drop "3" and add a new item with the new text.',
        ]);

        $replacementOutcome = $this->update(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => true], ['text' => 'Write two holiday posts']]);
        self::assertSame(
            "Task plan updated: 5 items (2 done, 3 open, 0 blocked). Added: 4 'Write two holiday posts' (at the end). Dropped: 3 'Write the holiday post' with its subtasks 3.1, 3.2.",
            $replacementOutcome->renderAppliedSummary()
        );
    }

    public function testF12AnUnchangedResendIsUnchanged(): void
    {
        self::assertTrue($this->update(self::s1(), self::S1_KEPT_TOP_LEVEL)->isUnchanged);
        self::assertTrue($this->update(self::s1(), self::s1WithAllSubtasksKept())->isUnchanged);
        self::assertTrue($this->update(self::s1(), [['key' => '1', 'text' => '  set the holiday hours (24–26 dec   closed) '], ['key' => '2'], ['key' => '3']])->isUnchanged, 'the stored text, sent again, is a check only');
    }

    public function testF13AnUnknownKeyIsRejectedWithTheKeyList(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => '5']], [
            'Key "5" does not exist in your plan. An existing item is named by its key from the list below; a NEW item carries no key — send it as {"text": "…"} and the platform assigns its key.',
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedKeys(): array
    {
        $grammar = ' is not a key. A key is the number in front of an item in "Your task plan" and nothing else: digits joined by single dots, each number 1–999 without a leading zero, no spaces, no title (1, 1.2, 1.2.3, 1.2.3.1).';
        return [
            'a key with a title that is not in the plan' => ['7.2.11 Update Contact Details', 'Key "7.2.11 Update Contact Details"' . $grammar . ' "7.2.11" is not in your plan either.'],
            'a key with its title' => ['2.2 Draft and post the replies', 'Key "2.2 Draft and post the replies"' . $grammar . ' Did you mean "2.2"?'],
            'a trailing dot' => ['2.2.', 'Key "2.2."' . $grammar . ' Did you mean "2.2"?'],
            'a leading zero' => ['02.2', 'Key "02.2"' . $grammar . ' Did you mean "2.2"?'],
            'a dash' => ['2-2', 'Key "2-2"' . $grammar . ' Did you mean "2.2"?'],
            'a leading space' => [' 2.2', 'Key " 2.2"' . $grammar . ' Did you mean "2.2"?'],
            'no number at all' => ['k12', 'Key "k12"' . $grammar],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedKeys')]
    public function testF14AMalformedKeyIsRejectedWithTheHintButNeverApplied(string $sentKey, string $expectedLine): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => $sentKey]], [$expectedLine]);
    }

    public function testF15AKeyOnANewItemIsRejectedAsUnknown(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => '4', 'text' => 'Mail the owner a summary']], [
            'Key "4" does not exist in your plan. An existing item is named by its key from the list below; a NEW item carries no key — send it as {"text": "…"} and the platform assigns its key.',
        ]);
    }

    public function testF16TheSameKeyTwiceIsRejected(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '2'], ['key' => '3']], [
            'Key "2" appears 2 times — each existing item appears exactly once.',
        ]);
    }

    public function testF17AnOmittedItemIsRejectedNeverDropped(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2']], [
            'Your call leaves out 3 \'Write the holiday post\'. Every existing item stays named: send {"key": "3"} to keep it, {"key": "3", "remove": true} to drop it. If these items belong to an earlier task, drop them with "remove": true and add the new items in this same call.',
        ]);
    }

    public function testF18AFiveLevelChainIsAcceptedAndItsFiveSegmentKeyAddressesTheTick(): void
    {
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [
                ['key' => '2.1', 'subtasks' => [['text' => 'Sort by rating', 'subtasks' => [['text' => 'Pick the 1-star ones', 'subtasks' => [['text' => 'Draft the apology']]]]]]],
                ['key' => '2.2'],
            ]],
            ['key' => '3'],
        ]));

        self::assertSame(
            "Task plan updated: 10 items (1 done, 9 open, 0 blocked). Added: 2.1.1 'Sort by rating' (first under 2.1), 2.1.1.1 'Pick the 1-star ones' (first under 2.1.1),"
                . " 2.1.1.1.1 'Draft the apology' (first under 2.1.1.1).",
            $taskPlanUpdateOutcome->renderAppliedSummary()
        );
        self::assertSame([], $taskPlanUpdateOutcome->feedback, 'no depth feedback (Q10b)');
        $tailHintLines = $changedPlan->items->buildTailHintLines();
        self::assertSame('  [ ] 2.1 Read the newest reviews', $tailHintLines[2], 'the done leaf now renders as an open container, its done note off the line');
        self::assertSame('        [ ] 2.1.1.1.1 Draft the apology ← next', $tailHintLines[5]);
        self::assertSame(['plan' => 3, '1' => 0, '2' => 2, '2.1' => 1, '2.1.1' => 1, '2.1.1.1' => 1, '2.1.1.1.1' => 0, '2.2' => 0, '3' => 2, '3.1' => 0, '3.2' => 0], self::countersOf($changedPlan));

        $tickResult = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.1.1.1.1', 'status' => 'done', 'statusNote' => 'apology drafted']]], $changedPlan, 0);
        self::assertSame(1, $tickResult['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $changedPlan->items->getItemByKey('2.1.1.1.1')->status);
    }

    public function testF19AnEmptyItemsListIsRejected(): void
    {
        $this->assertRejectedWithLines(self::s1(), [], [
            "'items' is empty — a plan needs at least one item. To drop items, send them with \"remove\": true; every other item stays listed.",
        ]);
    }

    public function testF20ARepeatedTextAmongSiblingsIsRejectedAndTheSameTextUnderAnotherParentIsAccepted(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => [['key' => '3.1'], ['key' => '3.2'], ['text' => 'Draft the post']]]], [
            'New item \'Draft the post\' under "3" repeats "3.1" \'Draft the post\' — keep "3.1" with {"key": "3.1"}; a new item needs a different text.',
        ]);
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => [['key' => '3.1'], ['key' => '3.2'], ['text' => 'Pin it'], ['text' => 'pin it']]]], [
            'New items 3 and 4 under "3" both read \'pin it\' — send it once.',
        ]);
        $changedPlan = $this->appliedPlan($this->update(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['text' => 'Draft the post']]], ['key' => '3']]));
        self::assertSame('Draft the post', $changedPlan->items->getItemByKey('2.3')->text, 'two items with one text under two parents stay two items');
        self::assertSame('Draft the post', $changedPlan->items->getItemByKey('3.1')->text);
    }

    public function testF25ATickInTheSameResponseAfterAnInsertLandsOnTheItemItsKeyNames(): void
    {
        $changedPlan = $this->appliedPlan($this->update(self::s1(), [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['text' => 'Thank the 5-star reviewers by name'], ['key' => '2.2']]],
            ['key' => '3'],
        ]));

        SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done', 'statusNote' => '3 replies posted']]], $changedPlan, 0);

        self::assertSame('[done] Draft and post the replies — 3 replies posted', self::treeByKey($changedPlan)['2.2']);
        self::assertSame('[open] Thank the 5-star reviewers by name', self::treeByKey($changedPlan)['2.3']);
    }

    public function testF26ANewUnrelatedTaskNeedsTheOldItemsDroppedExplicitly(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['text' => 'Find three new keywords']], [
            "Your call leaves out 1 'Set the holiday hours (24–26 Dec closed)', 2 'Answer the newest reviews', 3 'Write the holiday post'. Every existing item stays named: send {\"key\": \"1\"} to keep it, {\"key\": \"1\", \"remove\": true} to drop it. If these items belong to an earlier task, drop them with \"remove\": true and add the new items in this same call.",
        ]);

        $switchedPlan = $this->appliedPlan($switchOutcome = $this->update(self::s1(), [['key' => '1', 'remove' => true], ['key' => '2', 'remove' => true], ['key' => '3', 'remove' => true], ['text' => 'Find three new keywords']]));
        self::assertSame(
            "Task plan updated: 1 items (0 done, 1 open, 0 blocked). Added: 4 'Find three new keywords' (first at the top level)."
                . " Dropped: 1 'Set the holiday hours (24–26 Dec closed)' — its state leaves with it, 2 'Answer the newest reviews' with its subtasks 2.1, 2.2 — its state leaves with it,"
                . " 3 'Write the holiday post' with its subtasks 3.1, 3.2.",
            $switchOutcome->renderAppliedSummary()
        );
        self::assertSame(['4' => '[open] Find three new keywords'], self::treeByKey($switchedPlan));
    }

    public function testF27TheOldHabitOfResendingTextsWithoutKeysIsRejectedWithOneLinePerRepeatedTextAndTheOmissions(): void
    {
        $this->assertRejectedWithLines(self::s1(), [
            ['text' => Holiday::ITEM_1],
            ['text' => Holiday::ITEM_2, 'subtasks' => [['text' => Holiday::ITEM_2_1], ['text' => Holiday::ITEM_2_2]]],
            ['text' => Holiday::ITEM_3, 'subtasks' => [['text' => Holiday::ITEM_3_1], ['text' => Holiday::ITEM_3_2]]],
        ], [
            "New item 'Set the holiday hours (24–26 Dec closed)' at the top level repeats \"1\" 'Set the holiday hours (24–26 Dec closed)' — keep \"1\" with {\"key\": \"1\"}; a new item needs a different text.",
            "New item 'Answer the newest reviews' at the top level repeats \"2\" 'Answer the newest reviews' — keep \"2\" with {\"key\": \"2\"}; a new item needs a different text.",
            "New item 'Write the holiday post' at the top level repeats \"3\" 'Write the holiday post' — keep \"3\" with {\"key\": \"3\"}; a new item needs a different text.",
            "Your call leaves out 1 'Set the holiday hours (24–26 Dec closed)', 2 'Answer the newest reviews', 3 'Write the holiday post'. Every existing item stays named: send {\"key\": \"1\"} to keep it, {\"key\": \"1\", \"remove\": true} to drop it. If these items belong to an earlier task, drop them with \"remove\": true and add the new items in this same call.",
        ]);
    }

    public function testF28DroppingAParentWhoseSubtreeHoldsASeededItemIsRejected(): void
    {
        $planWithASeededSubtask = self::s1();
        $planWithASeededSubtask->items->getItemByKey('3.2')->seededByPurpose = true;
        $this->assertRejectedWithLines($planWithASeededSubtask, [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => true]], [
            '"3.2" is part of the seeded plan and cannot be dropped; dropping "3" would drop it.',
        ]);
    }

    public function testF29DroppingEveryItemIsRejected(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1', 'remove' => true], ['key' => '2', 'remove' => true], ['key' => '3', 'remove' => true]], [
            'This call would leave your plan without any item — a plan always holds at least one. To end the task, close the open items with set_task_status (not_applicable with the reason, e.g. "the owner cancelled it"); to start a different task, drop the old items and add the new ones in this same call.',
        ]);
    }

    public function testF30DroppingTheLastChildOfAContainerIsRejected(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => [['key' => '3.1', 'remove' => true], ['key' => '3.2', 'remove' => true]]]], [
            'This call drops every subtask of "3" \'Write the holiday post\' — an item that has subtasks keeps at least one. Drop "3" itself ({"key": "3", "remove": true}), keep one of its subtasks, or close the subtasks with set_task_status (not_applicable with the reason).',
        ]);
    }

    public function testF31AReplacementTransfersNothingToTheNewIdentity(): void
    {
        $standingPlan = self::s1();
        $standingPlan->items->getItemByKey('2.2')->statusNote = 'posted 3';
        $snapshotBefore = $standingPlan->buildDeepCopy();

        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update($standingPlan, [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2', 'remove' => true], ['text' => 'Draft and post the replies']]],
            ['key' => '3'],
        ]));

        self::assertSame(
            "Task plan updated: 7 items (2 done, 5 open, 0 blocked). Added: 2.3 'Draft and post the replies' (after 2.1). Dropped: 2.2 'Draft and post the replies' — its state leaves with it.",
            $taskPlanUpdateOutcome->renderAppliedSummary()
        );
        $newIdentity = $changedPlan->items->getItemByKey('2.3');
        self::assertSame(TaskPlanItem::STATUS_OPEN, $newIdentity->status);
        self::assertNull($newIdentity->statusNote);
        self::assertNull($newIdentity->evidenceMessageIndex);
        self::assertNull($newIdentity->platformResolvedAtMessageIndex);
        self::assertFalse($newIdentity->preResolvedAtSeed);
        self::assertNull($changedPlan->items->getItemByKey('2.2'));
        self::assertSame('posted 3', $snapshotBefore->items->getItemByKey('2.2')->statusNote, 'the earlier snapshot still holds the old item');
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function malformedStructures(): array
    {
        return [
            '(a) a string instead of an object' => ['Draft the post', 'Item 4 at the top level is a string, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).'],
            '(b) subtasks as a string' => [['key' => '3', 'subtasks' => 'draft, publish'], '"subtasks" of "3" is "draft, publish" — send a non-empty list of items, or leave "subtasks" out to keep the existing subtasks unchanged.'],
            '(c) subtasks empty' => [['key' => '3', 'subtasks' => []], '"subtasks" of "3" is [] — send a non-empty list of items, or leave "subtasks" out to keep the existing subtasks unchanged.'],
            '(d) subtasks as an object' => [['key' => '3', 'subtasks' => ['key' => '3.1']], '"subtasks" of "3" is {"key":"3.1"} — send a non-empty list of items, or leave "subtasks" out to keep the existing subtasks unchanged.'],
            '(e) an empty text' => [['text' => '   '], 'Item 4 at the top level has an empty "text" — a new item states its task in "text" as a non-empty string; an existing item needs no text.'],
            '(f) a number as text' => [['text' => 42], 'Item 4 at the top level has "text": 42 — a new item states its task in "text" as a non-empty string; an existing item needs no text.'],
            '(g) an empty object' => [[], 'Item 4 at the top level has neither a key nor a text — an existing item needs "key", a new item needs "text".'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedStructures')]
    public function testF32AMalformedStructureRejectsTheWholeCall(mixed $malformedElement, string $expectedLine): void
    {
        // the malformed element replaces 3 where it names 3, else it is a fourth top-level element
        $items = is_array($malformedElement) && ($malformedElement['key'] ?? null) === '3'
            ? [['key' => '1'], ['key' => '2'], $malformedElement]
            : [...self::S1_KEPT_TOP_LEVEL, $malformedElement];
        $this->assertRejectedWithLines(self::s1(), $items, [$expectedLine]);
    }

    public function testF32EveryViolationOfOneCallIsListedAtOnce(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, 'Draft the post', ['text' => 42], []], [
            'Item 4 at the top level is a string, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            'Item 5 at the top level has "text": 42 — a new item states its task in "text" as a non-empty string; an existing item needs no text.',
            'Item 6 at the top level has neither a key nor a text — an existing item needs "key", a new item needs "text".',
        ]);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function keysOfTheWrongJsonType(): array
    {
        return [
            'a number' => [['key' => 2.2], 'Key 2.2 was sent as a number — send keys as strings, exactly as "Your task plan" shows them (e.g. "key": "2.1"); a number can lose digits (7.10 arrives as 7.1), so take the key from the list below.'],
            'null on a new item' => [['key' => null, 'text' => 'Mail it'], 'Item 4 at the top level has "key": null — an existing item needs its key as a string ("key": "2.1"); a NEW item leaves "key" out and carries "text".'],
            'a boolean' => [['key' => true], 'Key true (item 4 at the top level) is not a key — send the item\'s number from "Your task plan" as a string ("key": "2.1").'],
            'a list' => [['key' => ['2', '2.1']], 'Key ["2","2.1"] (item 4 at the top level) is a list — each item carries ONE key, as a string ("key": "2.1").'],
            'an object' => [['key' => ['id' => '2']], 'Key {"id":"2"} (item 4 at the top level) is an object — each item carries ONE key, as a string ("key": "2.1").'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keysOfTheWrongJsonType')]
    public function testF33AKeyOfTheWrongJsonTypeIsRejected(array $element, string $expectedLine): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, $element], [$expectedLine]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function removalsThatAreNotARemoval(): array
    {
        return [
            'remove with a status' => [['key' => '3', 'remove' => true, 'status' => 'done'], '{"key": "3", "remove": true} drops the item, so it takes no other field (you also sent status). Send it alone to drop "3", or without "remove" to keep it.'],
            'remove with subtasks' => [['key' => '3', 'remove' => true, 'subtasks' => [['key' => '3.1']]], '{"key": "3", "remove": true} drops the item, so it takes no other field (you also sent subtasks). Send it alone to drop "3", or without "remove" to keep it.'],
            'remove as a string' => [['key' => '3', 'remove' => 'yes'], '"remove" of "3" is "yes" — only "remove": true exists; leave "remove" out to keep the item.'],
            'remove false' => [['key' => '3', 'remove' => false], '"remove" of "3" is false — only "remove": true exists; leave "remove" out to keep the item.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('removalsThatAreNotARemoval')]
    public function testF34ARemovalWithOtherFieldsOrNotTrueIsRejected(array $element, string $expectedLine): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], $element], [$expectedLine]);
    }

    public function testF34ARemovalOfANewItemIsRejected(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'x', 'remove' => true]], [
            '"remove": true needs the key of an existing item — a new item is simply left out.',
        ]);
    }

    public function testF35ALevelThatUsedAll999NumbersTakesNoNewItemAndNothingIsMinted(): void
    {
        $exhaustedTopLevel = self::s1();
        $exhaustedTopLevel->highestItemKeyNumber = 999;
        $this->assertRejectedWithLines($exhaustedTopLevel, [...self::S1_KEPT_TOP_LEVEL, ['text' => 'One more']], [
            'No new item fits at the top level: this level has used all 999 numbers (a number is never reused). Add the item under another parent, or close this plan with set_task_status and start a new one.',
        ]);
        self::assertSame(999, $exhaustedTopLevel->getHighestItemKeyNumber());

        $exhaustedSubtaskLevel = self::s1();
        $exhaustedSubtaskLevel->items->getItemByKey('2')->highestSubtaskKeyNumber = 999;
        $this->assertRejectedWithLines($exhaustedSubtaskLevel, [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['text' => 'One more']]], ['key' => '3']], [
            'No new item fits under "2": this level has used all 999 numbers (a number is never reused). Add the item under another parent, or close this plan with set_task_status and start a new one.',
        ]);

        $lastNumberLeft = self::s1();
        $lastNumberLeft->highestItemKeyNumber = 998;
        self::assertSame('999', $this->appliedPlan($this->update($lastNumberLeft, [...self::S1_KEPT_TOP_LEVEL, ['text' => 'The last one']]))->items->getElements()[3]->key);
    }

    public function testF36AStoredKeyIntegrityViolationPausesStructureChangesAndLeavesTheUnambiguousTicks(): void
    {
        $storedPlan = self::s1()->mapToRepository();
        $storedPlan['items'][1]['subtasks'][0]['key'] = '2.2';
        $corruptPlan = TaskPlan::fromRepositoryArray($storedPlan);
        self::assertSame(['"2.2" appears twice'], $corruptPlan->keyIntegrityViolations->lines);

        $this->assertRejectedWithLines($corruptPlan, [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail it']], [
            'Your stored plan has an inconsistency the platform must fix ("2.2" appears twice) — structure changes are paused. Keep working with set_task_status on the keys listed below; the platform has logged the defect.',
        ], "Your plan's keys: 1 'Set the holiday hours (24–26 Dec closed)' · 2 'Answer the newest reviews' · 3 'Write the holiday post' · 3.1 'Draft the post' · 3.2 'Publish it after the owner's approval'");

        $ambiguousTick = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done', 'statusNote' => 'x']]], $corruptPlan, 0);
        self::assertSame(0, $ambiguousTick['updatedCount'], 'a duplicate key names no item — never the first match');
        $unambiguousTick = SetTaskStatusTestTick::apply(['updates' => [['task' => '3.1', 'status' => 'done', 'statusNote' => 'drafted']]], $corruptPlan, 0);
        self::assertSame(1, $unambiguousTick['updatedCount']);
        self::assertSame('2.2', $corruptPlan->items->getItemByKey('2')->subtasks->getElements()[0]->key, 'no identity renumbered');
    }

    public function testF37aSixtyFourNewItemsAreAcceptedWholeWithoutAnySizeFeedback(): void
    {
        $items = [];
        for ($number = 1; $number <= 64; $number++) {
            $items[] = ['text' => 'Reply to review ' . $number];
        }
        $createdPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->create($items));

        self::assertSame('Task plan updated: 64 items (0 done, 64 open, 0 blocked).', $taskPlanUpdateOutcome->renderAppliedSummary());
        self::assertSame([], $taskPlanUpdateOutcome->feedback);
        self::assertSame(64, $createdPlan->getHighestItemKeyNumber());
        self::assertSame('Reply to review 64', $createdPlan->items->getItemByKey('64')->text);
        self::assertCount(64, $createdPlan->items->buildTailHintLines(), 'all 64 rendered');
    }

    public function testF37bSixtySubtasksAddedToS1AreAcceptedWhole(): void
    {
        $subtasks = [['key' => '3.1'], ['key' => '3.2']];
        for ($number = 1; $number <= 60; $number++) {
            $subtasks[] = ['text' => 'Post variant ' . $number];
        }
        $changedPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->update(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => $subtasks]]));

        self::assertSame(67, $changedPlan->items->countAllItems());
        self::assertSame(62, $changedPlan->items->getItemByKey('3')->highestSubtaskKeyNumber);
        self::assertCount(60, $taskPlanUpdateOutcome->addedItemDescriptions);
        self::assertSame("3.3 'Post variant 1' (after 3.2)", $taskPlanUpdateOutcome->addedItemDescriptions[0]);
        self::assertSame("3.62 'Post variant 60' (after 3.61)", $taskPlanUpdateOutcome->addedItemDescriptions[59]);
        self::assertSame([], $taskPlanUpdateOutcome->feedback);
    }

    /** S1 as a seeded plan: every item `seededByPurpose` (the seeded protections — unreachable through the dispatch, tested on their own). */
    protected static function seededS1(): TaskPlan
    {
        $seededPlan = self::s1();
        foreach ($seededPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItemAddress->item->seededByPurpose = true;
        }
        return $seededPlan;
    }

    public function testTheSeededProtectionsKeepTheOrderOfSeededSiblingsAndNameASeededMove(): void
    {
        $this->assertRejectedWithLines(self::seededS1(), [['key' => '1'], ['key' => '3'], ['key' => '2']], [
            'Seeded items keep their order: "2" stays before "3".',
        ]);
        $this->assertRejectedWithLines(self::seededS1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['key' => '3.2']]], ['key' => '3', 'subtasks' => [['key' => '3.1']]]], [
            'Item "3.2" was sent under "2", but it belongs under "3". An item never moves: "3.2" is part of the seeded plan and stays where it is. To keep it, name it inside its parent: {"key": "3", "subtasks": [{"key": "3.1"}, {"key": "3.2"}]} — a new item under "3" goes into that same list as {"text": "…"}.',
        ]);
        // a model item may stand anywhere among seeded ones
        self::assertNotNull($this->update(self::seededS1(), [['text' => 'Ask the owner first'], ...self::S1_KEPT_TOP_LEVEL])->taskPlan);
    }

    public function testAnOmittedSeededItemIsNamedAsSeededInTheOmissionLine(): void
    {
        $this->assertRejectedWithLines(self::seededS1(), [['key' => '1'], ['key' => '2']], [
            'Your call leaves out 3 \'Write the holiday post\' (seeded — keep it; close it with set_task_status not_applicable and a reason if it does not apply). Every existing item stays named: send {"key": "3"} to keep it, {"key": "3", "remove": true} to drop it.',
        ]);
    }

    public function testAnItemAddedByAnEarlierCallOfTheResponseIsNamedSoInTheOmissionLine(): void
    {
        // F24 at the domain level: the context names the keys an earlier call of the same response added
        $standingPlan = $this->appliedPlan($this->update(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail the owner a short summary']]));
        $taskPlanUpdateContext = $this->ownerTurnContext();
        $taskPlanUpdateContext->keysAddedEarlierInThisResponse = ['4'];
        $taskPlanUpdateOutcome = $this->update($standingPlan, [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['text' => 'Thank the 5-star reviewers by name'], ['key' => '2.2']]], ['key' => '3']], $taskPlanUpdateContext);

        self::assertSame(
            ['Your call leaves out 4 \'Mail the owner a short summary\' (added by an earlier call in this response). Every existing item stays named: send {"key": "4"} to keep it, {"key": "4", "remove": true} to drop it.'],
            $taskPlanUpdateOutcome->rejections->getLines()
        );
    }

    public function testWithoutAStandingPlanEveryKeyIsUnknownAndTheBlockSaysSo(): void
    {
        $this->assertRejectedWithLines(null, [['key' => '1'], ['text' => 'Mail it']], [
            'Key "1" does not exist in your plan. An existing item is named by its key from the list below; a NEW item carries no key — send it as {"text": "…"} and the platform assigns its key.',
        ], null);
    }

    public function testACreatedPlanStartsWithKeyOneAfterARetirement(): void
    {
        // a key's scope is ONE plan: a new plan starts at 1 again
        self::assertSame('1', $this->appliedPlan($this->create([['text' => 'Find three new keywords']]))->items->first()->key);
    }

    public function testTheSevenSegmentKeyConformsAndSegment1000DoesNot(): void
    {
        self::assertTrue(TaskPlanItem::isConformingKey('1.2.3.4.5.6.7'));
        self::assertSame('^[1-9][0-9]{0,2}(\.[1-9][0-9]{0,2})*$', TaskPlanItem::KEY_PATTERN);
        self::assertSame(999, TaskPlanItem::KEY_SEGMENT_MAX);
        self::assertFalse(TaskPlanItem::isConformingKey('2.1000'));
        self::assertSame(999, TaskPlanItem::allocateKeySegmentAfter(998));
        self::assertNull(TaskPlanItem::allocateKeySegmentAfter(999));
    }

    public function testTheReadSideMintsNoSegmentAbove999AndRecordsTheViolationInstead(): void
    {
        $storedPlan = self::s1()->mapToRepository();
        $storedPlan['items'][1]['highestSubtaskKeyNumber'] = 999;
        $storedPlan['items'][1]['subtasks'][] = ['text' => 'Keyless late reply', 'status' => 'open'];
        $readPlan = TaskPlan::fromRepositoryArray($storedPlan);

        $keylessItem = $readPlan->items->getItemByKey('2')->subtasks->getElements()[2];
        self::assertNull($keylessItem->key, 'no key above 999 is minted on read');
        self::assertSame(['an item without a key under "2" got no key: the level used all 999 numbers'], $readPlan->keyIntegrityViolations->lines);
        self::assertTrue($this->update($readPlan, self::S1_KEPT_TOP_LEVEL)->rejections->hasCode('R25'), 'the violation pauses structure changes');
    }

    public function testACreatedPlanAppliesTheStatusesItsNewItemsCarry(): void
    {
        $createdPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->create([
            ['text' => 'Read the reviews', 'status' => 'done', 'statusNote' => 'read 3'],
            ['text' => 'Post the reply', 'status' => 'blocked'],
            ['text' => 'Mail it', 'statusNote' => 'later'],
        ]));

        self::assertSame(['1' => '[done] Read the reviews — read 3', '2' => '[blocked] Post the reply', '3' => '[open] Mail it'], self::treeByKey($createdPlan));
        // the fields without a status are judged in the walk, the transitions then through the tick application,
        // its lines in the tick's words
        self::assertSame([
            'Item "3": statusNote/flags without a status change nothing on an open item — use set_task_status.',
            "Task '2': blocked without a statusNote — state in ONE line why.",
        ], $taskPlanUpdateOutcome->feedback);
        self::assertNull($createdPlan->resolvedAtTurnInitiatorMessageIndex, 'an item created terminal is a declaration, never a resolution');
    }

    public function testAnUnknownStatusIsNamedAndNotApplied(): void
    {
        $taskPlanUpdateOutcome = $this->update(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'status' => 'weird-status']]);

        self::assertTrue($taskPlanUpdateOutcome->isUnchanged);
        self::assertSame(["Task '3': status 'weird-status' not recognized — use open|done|blocked|not_applicable; not applied."], $taskPlanUpdateOutcome->feedback);
        self::assertTrue($taskPlanUpdateOutcome->hasGateRefusedStatus, 'a status the tick did not apply is a refused status');
    }

    /** @return array<string, array{string, string}> */
    public static function provideCascadeStatuses(): array
    {
        return [
            'blocked' => [TaskPlanItem::STATUS_BLOCKED, 'Not applied — 5 blocked update(s) (1, 2, 3, 4, 5) — that is a give-up cascade'],
            // the tick's cap covers not_applicable too, the resend's covered blocked only
            'not_applicable' => [TaskPlanItem::STATUS_NOT_APPLICABLE, 'Not applied — 5 not_applicable update(s) (1, 2, 3, 4, 5) — that is a give-up cascade'],
        ];
    }

    #[DataProvider('provideCascadeStatuses')]
    public function testTheCascadeCapNotesEveryTransitionOfTheCallIntoTheSameStatus(string $status, string $expectedCascadeLineStart): void
    {
        $items = [];
        for ($number = 1; $number <= 5; $number++) {
            $items[] = ['text' => 'Step ' . $number, 'status' => $status, 'statusNote' => 'needs step 0'];
        }
        $createdPlan = $this->appliedPlan($taskPlanUpdateOutcome = $this->create($items));

        self::assertSame([], $createdPlan->items->getItemsWithStatus($status));
        self::assertStringStartsWith($expectedCascadeLineStart, $taskPlanUpdateOutcome->feedback[0]);
        self::assertTrue($taskPlanUpdateOutcome->hasGateRefusedStatus);
    }

    public function testNoTimeContextNeededForAPurePlanUpdate(): void
    {
        // the gates read the transcript only when the context carries it
        self::assertNotNull($this->update(self::s1Gated(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2', 'status' => 'done', 'statusNote' => 'x']]], ['key' => '3']], TaskPlanUpdateContext::forTurn(0))->taskPlan);
        self::assertInstanceOf(TaskPlanItems::class, self::s1()->items);
    }
}
