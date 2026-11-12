<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Keys;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateRejection;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;

/**
 * One test per rejection line, R1–R25 with the four R4 variants and the second forms of R7, R9, R11,
 * R14 and R21 (R12 and R13 were deleted: no depth and no item-count rejection exists). Each call is sent to S1 and
 * must reject with exactly that line, the plan byte-identical afterwards ({@see assertRejectedWithLines()}).
 */
class UpdateTaskPlanRejectionLinesTest extends KeyedTaskPlanUpdateTestCase
{
    public function testR1ItemsMissingOrNotAList(): void
    {
        foreach ([[], ['items' => 'nope'], ['items' => ['key' => '1']]] as $arguments) {
            $standingPlan = self::s1();
            $rejections = $standingPlan->checkUpdate(UpdateTaskPlanArguments::fromArguments($arguments));
            self::assertSame(["'items' is missing or not a list — send the plan as items: [...]."], $rejections->getLines());
            self::assertSame([TaskPlanUpdateRejection::CODE_ITEMS_NOT_A_LIST], $rejections->getCodes());
        }
    }

    public function testR2ItemsEmpty(): void
    {
        $this->assertRejectedWithLines(self::s1(), [], ["'items' is empty — a plan needs at least one item. To drop items, send them with \"remove\": true; every other item stays listed."]);
    }

    public function testR3MalformedKey(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => '2.2 Draft and post the replies']], [
            'Key "2.2 Draft and post the replies" is not a key. A key is the number in front of an item in "Your task plan" and nothing else: digits joined by single dots, each number 1–999 without a leading zero, no spaces, no title (1, 1.2, 1.2.3, 1.2.3.1). Did you mean "2.2"?',
        ]);
    }

    public function testR4KeySentAsNumber(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => 7.1]], [
            'Key 7.1 was sent as a number — send keys as strings, exactly as "Your task plan" shows them (e.g. "key": "2.1"); a number can lose digits (7.10 arrives as 7.1), so take the key from the list below.',
        ]);
    }

    public function testR4KeySentAsNull(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['key' => null]]], ['key' => '3']], [
            'Item 3 under "2" has "key": null — an existing item needs its key as a string ("key": "2.1"); a NEW item leaves "key" out and carries "text".',
        ]);
    }

    public function testR4KeySentAsBoolean(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => false]], [
            'Key false (item 4 at the top level) is not a key — send the item\'s number from "Your task plan" as a string ("key": "2.1").',
        ]);
    }

    public function testR4KeySentAsListOrObject(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => ['3']]], [
            'Key ["3"] (item 4 at the top level) is a list — each item carries ONE key, as a string ("key": "2.1").',
        ]);
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['key' => ['key' => '3']]], [
            'Key {"key":"3"} (item 4 at the top level) is an object — each item carries ONE key, as a string ("key": "2.1").',
        ]);
    }

    public function testR5KeyRepeated(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3'], ['key' => '3'], ['key' => '3']], [
            'Key "3" appears 3 times — each existing item appears exactly once.',
        ]);
    }

    public function testR6KeyUnknown(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2'], ['key' => '2.9']]], ['key' => '3']], [
            'Key "2.9" does not exist in your plan. An existing item is named by its key from the list below; a NEW item carries no key — send it as {"text": "…"} and the platform assigns its key.',
        ]);
    }

    public function testR7ItemMovedAndItsSeededVariant(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2']]], ['key' => '3'], ['key' => '3.1']], [
            'Item "3.1" was sent at the top level, but it belongs under "3". An item never moves: keep "3.1" where it is; to do this work elsewhere, drop it ({"key": "3.1", "remove": true}) and add a new item there. To keep it, name it inside its parent: {"key": "3", "subtasks": [{"key": "3.1"}, {"key": "3.2"}]} — a new item under "3" goes into that same list as {"text": "…"}.',
        ]);
        $seededPlan = self::s1();
        $seededPlan->items->getItemByKey('3.1')->seededByPurpose = true;
        $this->assertRejectedWithLines($seededPlan, [...self::S1_KEPT_TOP_LEVEL, ['key' => '3.1']], [
            'Item "3.1" was sent at the top level, but it belongs under "3". An item never moves: "3.1" is part of the seeded plan and stays where it is. To keep it, name it inside its parent: {"key": "3", "subtasks": [{"key": "3.1"}, {"key": "3.2"}]} — a new item under "3" goes into that same list as {"text": "…"}.',
        ]);
    }

    public function testR7ShowsTheCallThatKeepsATopLevelItemInPlace(): void
    {
        self::assertSame(' To keep it, list {"key": "3"} in the top-level items.', TaskPlanUpdateRejection::buildCallThatKeepsItemInPlace('3', '', []));
        self::assertSame(
            ' To keep it, name it inside its parent: {"key": "2", "subtasks": [{"key": "2.1"}, {"key": "2.2"}]} — a new item under "2" goes into that same list as {"text": "…"}.',
            TaskPlanUpdateRejection::buildCallThatKeepsItemInPlace('2.1', '2', ['2.1', '2.2'])
        );
    }

    public function testR8ItemsLeftOut(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.2']]], ['key' => '3']], [
            'Your call leaves out 2.1 \'Read the newest reviews\'. Every existing item stays named: send {"key": "2.1"} to keep it, {"key": "2.1", "remove": true} to drop it.',
        ]);
    }

    public function testR8NamesTheDropWhenAnEarlierTasksTopLevelItemsAreLeftOut(): void
    {
        // a new task's creation over a standing plan — the line says how the earlier task's items leave
        $this->assertRejectedWithLines(self::s1(), [['text' => 'Find three new keywords']], [
            "Your call leaves out 1 'Set the holiday hours (24–26 Dec closed)', 2 'Answer the newest reviews', 3 'Write the holiday post'. Every existing item stays named:"
                . ' send {"key": "1"} to keep it, {"key": "1", "remove": true} to drop it. If these items belong to an earlier task, drop them with "remove": true and add'
                . ' the new items in this same call.',
        ]);
    }

    public function testR8NamesOnlyTheDroppableKeysWhenTheLineMixesThemWithItemsThatStay(): void
    {
        // a left-out subtask is no earlier task's item — the drop hint names item 1 alone
        $this->assertRejectedWithLines(self::s1(), [['key' => '2', 'subtasks' => [['key' => '2.2']]], ['key' => '3'], ['text' => 'Find three new keywords']], [
            "Your call leaves out 2.1 'Read the newest reviews', 1 'Set the holiday hours (24–26 Dec closed)'. Every existing item stays named:"
                . ' send {"key": "2.1"} to keep it, {"key": "2.1", "remove": true} to drop it. If item 1 belongs to an earlier task, drop it with "remove": true and add'
                . ' the new items in this same call.',
        ]);
    }

    public function testR8EarlierTaskHintNamesTheDroppableKeysOfAMixedLine(): void
    {
        self::assertSame('', TaskPlanUpdateRejection::buildEarlierTaskItemsHint([], 2));
        self::assertSame(TaskPlanUpdateRejection::EARLIER_TASK_ITEMS_HINT, TaskPlanUpdateRejection::buildEarlierTaskItemsHint(['1', '2'], 2));
        self::assertSame(
            ' If items 1, 3 belong to an earlier task, drop them with "remove": true and add the new items in this same call.',
            TaskPlanUpdateRejection::buildEarlierTaskItemsHint(['1', '3'], 3)
        );
    }

    public function testR9TextChangedAndItsSwapVariant(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'text' => 'Answer every review'], ['key' => '3']], [
            'Item "2" reads \'Answer the newest reviews\'; you sent \'Answer every review\'. An item\'s text is fixed — send {"key": "2"} without text. To change what the item asks for, drop "2" and add a new item with the new text.',
        ]);
        // a model that assumes an inserted item took the old number: the sent text belongs to another key
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1', 'text' => 'Draft and post the replies'], ['key' => '2.2']]], ['key' => '3']], [
            'Item "2.1" reads \'Read the newest reviews\'; the text you sent belongs to "2.2". Keys never change and do not follow the order — send "2.2" for that item.',
        ]);
    }

    public function testR10NeitherKeyNorText(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['status' => 'open']], [
            'Item 4 at the top level has neither a key nor a text — an existing item needs "key", a new item needs "text".',
        ]);
    }

    public function testR10AKeySentInTheTaskFieldIsNamedAsAKeyNeverReadAsAText(): void
    {
        // {"task": "2"} is the address shape of set_task_status — never a new item called "2"
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['task' => '2'], ['key' => '3']], [
            'Item 2 at the top level has "task": "2" — an existing item is named by "key", not "task": send {"key": "2"}; a new item states its task in "text".',
        ]);
        $createdPlan = $this->appliedPlan($this->create([['task' => 'Draft the post']]));
        self::assertSame('Draft the post', $createdPlan->items->first()->text, 'a text in the "task" field still names a new item');
    }

    public function testR11NewTextRepeatsASiblingAndTwoNewSiblingsRepeatEachOther(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'write the HOLIDAY post']], [
            'New item \'write the HOLIDAY post\' at the top level repeats "3" \'Write the holiday post\' — keep "3" with {"key": "3"}; a new item needs a different text.',
        ]);
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail it', 'subtasks' => [['text' => 'Draft it'], ['text' => 'Draft it']]]], [
            'New items 1 and 2 under the new item \'Mail it\' both read \'Draft it\' — send it once.',
        ]);
    }

    public function testR11ASiblingDroppedInTheSameCallDoesNotCount(): void
    {
        self::assertNotNull($this->update(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => true], ['text' => 'Write the holiday post']])->taskPlan);
    }

    public function testR14SeededItemDroppedAndDroppedWithItsParent(): void
    {
        $seededPlan = self::s1();
        $seededPlan->items->getItemByKey('2.1')->seededByPurpose = true;
        $this->assertRejectedWithLines($seededPlan, [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1', 'remove' => true], ['key' => '2.2']]], ['key' => '3']], [
            '"2.1" is part of the seeded plan and cannot be dropped — close it with set_task_status: not_applicable and the reason it does not apply.',
        ]);
        $this->assertRejectedWithLines($seededPlan, [['key' => '1'], ['key' => '2', 'remove' => true], ['key' => '3']], [
            '"2.1" is part of the seeded plan and cannot be dropped; dropping "2" would drop it.',
        ]);
    }

    public function testR15RemoveWithoutKey(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail it', 'remove' => true]], [
            '"remove": true needs the key of an existing item — a new item is simply left out.',
        ]);
    }

    public function testR16SeededOrderChanged(): void
    {
        $seededPlan = self::s1();
        $seededPlan->items->getItemByKey('3.1')->seededByPurpose = true;
        $seededPlan->items->getItemByKey('3.2')->seededByPurpose = true;
        $this->assertRejectedWithLines($seededPlan, [['key' => '1'], ['key' => '2'], ['key' => '3', 'subtasks' => [['key' => '3.2'], ['key' => '3.1']]]], [
            'Seeded items keep their order: "3.1" stays before "3.2".',
        ]);
    }

    public function testR17PlanLeftEmpty(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1', 'remove' => true], ['key' => '2', 'remove' => true], ['key' => '3', 'remove' => true]], [
            'This call would leave your plan without any item — a plan always holds at least one. To end the task, close the open items with set_task_status (not_applicable with the reason, e.g. "the owner cancelled it"); to start a different task, drop the old items and add the new ones in this same call.',
        ]);
    }

    public function testR18ContainerLeftEmpty(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => [['key' => '2.1', 'remove' => true], ['key' => '2.2', 'remove' => true]]], ['key' => '3']], [
            'This call drops every subtask of "2" \'Answer the newest reviews\' — an item that has subtasks keeps at least one. Drop "2" itself ({"key": "2", "remove": true}), keep one of its subtasks, or close the subtasks with set_task_status (not_applicable with the reason).',
        ]);
    }

    public function testR19ItemNotAnObject(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, null, 7, true, ['a', 'b']], [
            'Item 4 at the top level is null, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            'Item 5 at the top level is a number, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            'Item 6 at the top level is a boolean, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            'Item 7 at the top level is a list, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
        ]);
    }

    public function testR19StandsAloneWhenNoElementOfAListIsAnObject(): void
    {
        // a list of non-objects empties no plan and no container — R19 names it, R17 / R18 stay silent
        $this->assertRejectedWithLines(null, ['Draft the post', 7], [
            'Item 1 at the top level is a string, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            'Item 2 at the top level is a number, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
        ], null);
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2', 'subtasks' => ['Read them all']], ['key' => '3']], [
            'Item 1 under "2" is a string, not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).',
            "Your call leaves out 2.1 'Read the newest reviews', 2.2 'Draft and post the replies'. Every existing item stays named: send {\"key\": \"2.1\"} to keep it, {\"key\": \"2.1\", \"remove\": true} to drop it.",
        ]);
    }

    public function testR20SubtasksMalformedOnANewItem(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => 'Mail it', 'subtasks' => 'draft, send']], [
            '"subtasks" of the new item \'Mail it\' is "draft, send" — send a non-empty list of items, or leave "subtasks" out to keep the existing subtasks unchanged.',
        ]);
    }

    public function testR21TextEmptyAndTextNotAString(): void
    {
        $this->assertRejectedWithLines(self::s1(), [...self::S1_KEPT_TOP_LEVEL, ['text' => '']], [
            'Item 4 at the top level has an empty "text" — a new item states its task in "text" as a non-empty string; an existing item needs no text.',
        ]);
        $this->assertRejectedWithLines(self::s1(), [['key' => '1', 'text' => ['Set']], ['key' => '2'], ['key' => '3']], [
            'Item 1 at the top level has "text": ["Set"] — a new item states its task in "text" as a non-empty string; an existing item needs no text.',
        ]);
    }

    public function testR22RemoveWithOtherFields(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => true, 'text' => 'Write the holiday post', 'statusNote' => 'cancelled']], [
            '{"key": "3", "remove": true} drops the item, so it takes no other field (you also sent text, statusNote). Send it alone to drop "3", or without "remove" to keep it.',
        ]);
    }

    public function testR23RemoveNotTrue(): void
    {
        $this->assertRejectedWithLines(self::s1(), [['key' => '1'], ['key' => '2'], ['key' => '3', 'remove' => 1]], [
            '"remove" of "3" is 1 — only "remove": true exists; leave "remove" out to keep the item.',
        ]);
    }

    public function testR24KeyNumbersExhausted(): void
    {
        $standingPlan = self::s1();
        $standingPlan->highestItemKeyNumber = 998;
        $this->assertRejectedWithLines($standingPlan, [...self::S1_KEPT_TOP_LEVEL, ['text' => 'One'], ['text' => 'Two']], [
            'No new item fits at the top level: this level has used all 999 numbers (a number is never reused). Add the item under another parent, or close this plan with set_task_status and start a new one.',
        ]);
    }

    public function testR25StoredKeysInconsistent(): void
    {
        $storedPlan = self::s1()->mapToRepository();
        $storedPlan['items'][2]['subtasks'][0]['key'] = '2.7';
        $corruptPlan = TaskPlan::fromRepositoryArray($storedPlan);

        $this->assertRejectedWithLines($corruptPlan, self::S1_KEPT_TOP_LEVEL, [
            'Your stored plan has an inconsistency the platform must fix ("2.7" sits under "3") — structure changes are paused. Keep working with set_task_status on the keys listed below; the platform has logged the defect.',
        ], "Your plan's keys: 1 'Set the holiday hours (24–26 Dec closed)' · 2 'Answer the newest reviews' · 2.1 'Read the newest reviews' · 2.2 'Draft and post the replies'"
            . " · 3 'Write the holiday post' · 2.7 'Draft the post' · 3.2 'Publish it after the owner's approval'");
    }
}
