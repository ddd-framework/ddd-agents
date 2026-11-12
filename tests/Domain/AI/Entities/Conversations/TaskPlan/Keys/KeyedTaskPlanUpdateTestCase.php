<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Keys;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateRejections;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanCharacterizationTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;

/**
 * The shared builders of the keyed `update_task_plan` tests: the reference plan as STORED (read back,
 * so every item carries its key and the counters plan 3, item 2 → 2, item 3 → 2), one call applied the way the live path
 * applies it ({@see TaskPlan::applyUpdate()} / {@see TaskPlan::createFromUpdate()}), the tree described BY KEY, and the
 * rejection invariant — a rejected call leaves the serialized tree, every counter and every stamp byte-identical — asserted on
 * every rejection.
 */
abstract class KeyedTaskPlanUpdateTestCase extends TaskPlanCharacterizationTestCase
{
    /** The key list of S1 every rejection block ends on. */
    protected const string S1_KEY_LIST = "Your plan's keys: 1 'Set the holiday hours (24–26 Dec closed)' · 2 'Answer the newest reviews' · 2.1 'Read the newest reviews'"
        . " · 2.2 'Draft and post the replies' · 3 'Write the holiday post' · 3.1 'Draft the post' · 3.2 'Publish it after the owner's approval'";

    /** The S1 keys sent as they stand, without subtasks — every item kept unchanged. */
    protected const array S1_KEPT_TOP_LEVEL = [['key' => '1'], ['key' => '2'], ['key' => '3']];

    /** S1 (1 done, 2.1 done, the rest open) as stored. */
    protected static function s1(): TaskPlan
    {
        return Holiday::s1()->buildDeepCopy();
    }

    /** S1 with the gates of the gate matrix (2.2 `replyToReview`, 3.1 criterion `post.drafted`, 3.2 `publishPost`) as stored. */
    protected static function s1Gated(): TaskPlan
    {
        return Holiday::s1Gated()->buildDeepCopy();
    }

    /** The context of a call in the owner turn that starts at message 0, with that owner message on the record. */
    protected function ownerTurnContext(): TaskPlanUpdateContext
    {
        return TaskPlanUpdateContext::forTurn(0, $this->messages([$this->ownerText(0, 'Close us on 24–26 Dec, answer the newest reviews and write a holiday post.')]));
    }

    /** @param array<int, mixed> $items the call's `items` */
    protected function update(TaskPlan $standingTaskPlan, array $items, ?TaskPlanUpdateContext $taskPlanUpdateContext = null): TaskPlanUpdateOutcome
    {
        return $standingTaskPlan->applyUpdate(UpdateTaskPlanArguments::fromArguments(['items' => $items]), $taskPlanUpdateContext ?? $this->ownerTurnContext());
    }

    /** @param array<int, mixed> $items */
    protected function create(array $items, ?TaskPlanUpdateContext $taskPlanUpdateContext = null): TaskPlanUpdateOutcome
    {
        return TaskPlan::createFromUpdate(UpdateTaskPlanArguments::fromArguments(['items' => $items]), $taskPlanUpdateContext ?? $this->ownerTurnContext());
    }

    /** The applied outcome's plan; fails when the call was rejected or changed nothing. */
    protected function appliedPlan(TaskPlanUpdateOutcome $taskPlanUpdateOutcome): TaskPlan
    {
        self::assertFalse($taskPlanUpdateOutcome->isRejected(), 'rejected: ' . implode(' | ', $taskPlanUpdateOutcome->rejections?->getLines() ?? []));
        self::assertNotNull($taskPlanUpdateOutcome->taskPlan, 'the call changed the plan');
        return $taskPlanUpdateOutcome->taskPlan;
    }

    /**
     * Apply `$items` to `$standingTaskPlan`, assert the call is REJECTED with exactly `$expectedLines` (the full block,
     * the plan's key list included), and that the standing plan is byte-identical afterwards — items, counters, stamps
     * (the rejection invariant).
     *
     * @param array<int, mixed> $items
     * @param string[] $expectedLines
     */
    protected function assertRejectedWithLines(?TaskPlan $standingTaskPlan, array $items, array $expectedLines, ?string $expectedKeyList = self::S1_KEY_LIST): TaskPlanUpdateRejections
    {
        $storedBefore = $standingTaskPlan !== null ? json_encode($standingTaskPlan->mapToRepository(), self::PERSISTED_JSON_FLAGS) : null;
        $taskPlanUpdateOutcome = $standingTaskPlan !== null ? $this->update($standingTaskPlan, $items) : $this->create($items);

        self::assertTrue($taskPlanUpdateOutcome->isRejected(), 'the call is rejected');
        self::assertNull($taskPlanUpdateOutcome->taskPlan);
        self::assertSame($expectedLines, $taskPlanUpdateOutcome->rejections->getLines());
        self::assertSame(
            TaskPlanUpdateRejections::REJECTION_HEADER . "\n"
                . implode("\n", array_map(static fn (string $line): string => '- ' . $line, $expectedLines)) . "\n"
                . ($expectedKeyList ?? TaskPlanUpdateRejections::NO_PLAN_KEY_LINE) . "\n"
                . TaskPlanUpdateRejections::VALID_SHAPES_LINE,
            $taskPlanUpdateOutcome->rejections->renderBlock($standingTaskPlan?->items)
        );
        if ($standingTaskPlan !== null) {
            self::assertSame($storedBefore, json_encode($standingTaskPlan->mapToRepository(), self::PERSISTED_JSON_FLAGS), 'nothing changed: items, counters, stamps');
        }
        return $taskPlanUpdateOutcome->rejections;
    }

    /**
     * The tree as one line per node in pre-order, keyed by the item KEY: `[status] text — note`.
     *
     * @return array<int|string, string>
     */
    protected static function treeByKey(TaskPlan $taskPlan): array
    {
        $linesByKey = [];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            $linesByKey[(string)$taskPlanItem->key] = '[' . $taskPlanItem->status . '] ' . $taskPlanItem->text
                . ($taskPlanItem->statusNote !== null && $taskPlanItem->statusNote !== '' ? ' — ' . $taskPlanItem->statusNote : '');
        }
        return $linesByKey;
    }

    /**
     * Every counter of the plan: the top level, then each item's subtask counter by key.
     *
     * @return array<string, int>
     */
    protected static function countersOf(TaskPlan $taskPlan): array
    {
        $counters = ['plan' => $taskPlan->getHighestItemKeyNumber()];
        foreach ($taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $counters[(string)$taskPlanItemAddress->item->key] = $taskPlanItemAddress->item->highestSubtaskKeyNumber;
        }
        return $counters;
    }

    /** @return array<int, array<string, mixed>> the item arrays of S1, unchanged, for a call that names every item as stored */
    protected static function s1WithAllSubtasksKept(): array
    {
        return [
            ['key' => '1'],
            ['key' => '2', 'subtasks' => [['key' => '2.1'], ['key' => '2.2']]],
            ['key' => '3', 'subtasks' => [['key' => '3.1'], ['key' => '3.2']]],
        ];
    }
}
