<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The strict address check: a trailing newline is no key (the key grammar anchors at the very end
 * of the string), a JSON boolean is answered with an open key of THIS plan as the example (never a fixed one, none when
 * nothing is open), and a JSON number suggests a key only when it is an integer naming an existing key — a decimal never:
 * `7.10` arrives as `7.1`, which may be another item.
 */
class SetTaskStatusAddressHintsTest extends UnitTestCase
{
    /** 1 · 7 › 7.1, 7.2 … 7.10 — key 7.1 and key 7.10 both exist; 1 is done. */
    protected function plan(string $statusOfSeven = TaskPlanItem::STATUS_OPEN): TaskPlan
    {
        $subtasks = [];
        for ($segment = 1; $segment <= 10; $segment++) {
            $subtasks[] = ['text' => 'Step ' . $segment, 'key' => '7.' . $segment, 'status' => $statusOfSeven, 'statusNote' => $statusOfSeven === TaskPlanItem::STATUS_OPEN ? null : 'x'];
        }
        return TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Done already', 'key' => '1', 'status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'x'],
            ['text' => 'Profile', 'key' => '7', 'status' => TaskPlanItem::STATUS_OPEN, 'highestSubtaskKeyNumber' => 10, 'subtasks' => $subtasks],
        ], 'highestItemKeyNumber' => 7]);
    }

    /** @return string[] */
    protected function rejectionLinesFor(mixed $rawTaskAddress, ?TaskPlan $taskPlan = null): array
    {
        return SetTaskStatusAddressCheck::ofUpdates([['task' => $rawTaskAddress, 'status' => 'done']], ($taskPlan ?? $this->plan())->items)->getRejectionLines();
    }

    public function testATrailingNewlineIsNoKey(): void
    {
        self::assertTrue(TaskPlanItem::isConformingKey('7.2'));
        self::assertFalse(TaskPlanItem::isConformingKey("7.2\n"));
        self::assertFalse(TaskPlanItem::isConformingKey("7\n"));
        self::assertNull(TaskPlanItem::getLastKeySegment("7.2\n"));
        self::assertSame(["Task '7.2\n' is not a key — the number only, digits joined by single dots; send \"task\": \"7.2\"."], $this->rejectionLinesFor("7.2\n"));
    }

    public function testABooleanIsAnsweredWithAnOpenKeyOfThisPlan(): void
    {
        self::assertSame(['Task true was sent as a boolean — send the item\'s number from "Your task plan" as a string ("task": "7.1").'], $this->rejectionLinesFor(true));
        self::assertSame(
            ['Task false was sent as a boolean — send the item\'s number from "Your task plan" as a string.'],
            $this->rejectionLinesFor(false, $this->plan(TaskPlanItem::STATUS_DONE)),
            'nothing open — no example at all'
        );
    }

    public function testADecimalNumberSuggestsNoKey(): void
    {
        // the model meant 7.10; JSON delivers 7.1 — a key exists for both, so a suggestion would name the wrong item
        self::assertSame(['Task 7.1 was sent as a number — send the key as a string, exactly as "Your task plan" shows it.'], $this->rejectionLinesFor(7.10));
        // a float, even one whose value is the existing key 7, suggests nothing
        self::assertSame(['Task 7 was sent as a number — send the key as a string, exactly as "Your task plan" shows it.'], $this->rejectionLinesFor(7.0));
    }

    public function testAnIntegerSuggestsItsKeyOnlyWhenTheKeyExists(): void
    {
        self::assertSame(['Task 7 was sent as a number — send "task": "7".'], $this->rejectionLinesFor(7));
        self::assertSame(['Task 9 was sent as a number — send the key as a string, exactly as "Your task plan" shows it.'], $this->rejectionLinesFor(9));
        self::assertSame(['Task 0 was sent as a number — send the key as a string, exactly as "Your task plan" shows it.'], $this->rejectionLinesFor(0));
    }
}
