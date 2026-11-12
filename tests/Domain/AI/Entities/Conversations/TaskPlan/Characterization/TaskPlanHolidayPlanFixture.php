<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;

/**
 * The reference plan P0 (a conversation without purpose; the owner: "close us on 24–26 Dec, answer the
 * newest reviews and write a holiday post") and its state S1 after a few ticks, as in-memory trees, plus the gated S1
 * variant the key resend matrix needs (two leaves carrying a tool gate, one a criterion only). The number paths of P0:
 * 1, 2, 2.1, 2.2, 3, 3.1, 3.2.
 */
class TaskPlanHolidayPlanFixture
{
    public const string ITEM_1 = 'Set the holiday hours (24–26 Dec closed)';
    public const string ITEM_2 = 'Answer the newest reviews';
    public const string ITEM_2_1 = 'Read the newest reviews';
    public const string ITEM_2_2 = 'Draft and post the replies';
    public const string ITEM_3 = 'Write the holiday post';
    public const string ITEM_3_1 = 'Draft the post';
    public const string ITEM_3_2 = "Publish it after the owner's approval";

    /** The P0 tree: every item open. */
    public static function p0(): TaskPlan
    {
        return self::plan([
            self::item(self::ITEM_1),
            self::container(self::ITEM_2, [self::item(self::ITEM_2_1), self::item(self::ITEM_2_2)]),
            self::container(self::ITEM_3, [self::item(self::ITEM_3_1), self::item(self::ITEM_3_2)]),
        ]);
    }

    /** The S1 tree: 1 done, 2.1 done, the rest open. */
    public static function s1(): TaskPlan
    {
        return self::plan([
            self::item(self::ITEM_1, TaskPlanItem::STATUS_DONE, 'closed 24–26 Dec saved'),
            self::container(self::ITEM_2, [
                self::item(self::ITEM_2_1, TaskPlanItem::STATUS_DONE, '3 new, 1 negative'),
                self::item(self::ITEM_2_2),
            ]),
            self::container(self::ITEM_3, [self::item(self::ITEM_3_1), self::item(self::ITEM_3_2)]),
        ]);
    }

    /**
     * S1 with seeded declarations: 2.2 carries the tool gate `replyToReview`, 3.1 the criterion `post.drafted` (no
     * gate), 3.2 the tool gate `publishPost`.
     */
    public static function s1Gated(): TaskPlan
    {
        $taskPlan = self::s1();
        $taskPlan->items->getItemByNumberPath('2.2')->doneRequiresToolCall = 'replyToReview';
        $taskPlan->items->getItemByNumberPath('3.1')->doneCriterion = 'post.drafted';
        $taskPlan->items->getItemByNumberPath('3.2')->doneRequiresToolCall = 'publishPost';
        return $taskPlan;
    }

    /**
     * S1 as the `update_task_plan` items a model resends today (text, status, statusNote, subtasks) — the starting point
     * every matrix row edits.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function s1ResendItems(): array
    {
        return [
            ['text' => self::ITEM_1, 'status' => 'done', 'statusNote' => 'closed 24–26 Dec saved'],
            ['text' => self::ITEM_2, 'status' => 'open', 'subtasks' => [
                ['text' => self::ITEM_2_1, 'status' => 'done', 'statusNote' => '3 new, 1 negative'],
                ['text' => self::ITEM_2_2, 'status' => 'open'],
            ]],
            ['text' => self::ITEM_3, 'status' => 'open', 'subtasks' => [
                ['text' => self::ITEM_3_1, 'status' => 'open'],
                ['text' => self::ITEM_3_2, 'status' => 'open'],
            ]],
        ];
    }

    public static function item(string $text, string $status = TaskPlanItem::STATUS_OPEN, ?string $statusNote = null): TaskPlanItem
    {
        $taskPlanItem = TaskPlanItem::newInstance();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = $status;
        $taskPlanItem->statusNote = $statusNote;
        return $taskPlanItem;
    }

    /** @param TaskPlanItem[] $subtaskList */
    public static function container(string $text, array $subtaskList): TaskPlanItem
    {
        $taskPlanItem = self::item($text);
        $taskPlanItem->subtasks = self::itemSet($subtaskList);
        return $taskPlanItem;
    }

    /** @param TaskPlanItem[] $taskPlanItemList */
    public static function itemSet(array $taskPlanItemList): TaskPlanItems
    {
        $taskPlanItems = new TaskPlanItems();
        foreach ($taskPlanItemList as $taskPlanItem) {
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    /** @param TaskPlanItem[] $taskPlanItemList */
    public static function plan(array $taskPlanItemList): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = self::itemSet($taskPlanItemList);
        return $taskPlan;
    }

    /**
     * The tree as one line per node in pre-order, keyed by today's number path: `[status] text — note {declarations}`.
     * The declarations shown are the ones the matrix moves: the done tool gate, the criterion and the four
     * declarations the resend carry drops.
     *
     * @return array<int|string, string> keyed by the number path (PHP turns a top-level path such as "1" into an int key)
     */
    public static function describeTree(TaskPlan $taskPlan): array
    {
        $linesByNumberPath = [];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            $declarations = [];
            if ($taskPlanItem->doneRequiresToolCall !== null) {
                $declarations[] = 'gate:' . $taskPlanItem->doneRequiresToolCall;
            }
            if ($taskPlanItem->doneCriterion !== null) {
                $declarations[] = 'criterion:' . $taskPlanItem->doneCriterion;
            }
            if ($taskPlanItem->minReasoningEffort !== null) {
                $declarations[] = 'minEffort:' . $taskPlanItem->minReasoningEffort;
            }
            $linesByNumberPath[$taskPlanItemAddress->numberPath] = '[' . $taskPlanItem->status . '] ' . $taskPlanItem->text
                . ($taskPlanItem->statusNote !== null ? ' — ' . $taskPlanItem->statusNote : '')
                . ($declarations !== [] ? ' {' . implode(', ', $declarations) . '}' : '');
        }
        return $linesByNumberPath;
    }
}
