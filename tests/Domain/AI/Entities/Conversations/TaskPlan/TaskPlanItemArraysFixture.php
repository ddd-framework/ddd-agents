<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;

/**
 * A model-written plan for a test, built from item arrays (`text`, `status`, `statusNote`, `subtasks`, any stored field)
 * through the stored-plan read ({@see TaskPlan::mapFromRepository()}): a keyless tree gets its number paths as keys, an
 * item without a declaration is the model's. Replaces the full-replace parse of calls recorded before keys, which is
 * gone.
 */
class TaskPlanItemArraysFixture
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param int|null $updatedAtTurnInitiatorMessageIndex the turn that wrote the plan (null = never stamped)
     */
    public static function plan(array $items, ?int $updatedAtTurnInitiatorMessageIndex = 0): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->mapFromRepository(['items' => $items]);
        if ($updatedAtTurnInitiatorMessageIndex !== null) {
            $taskPlan->markUpdatedInTurn($updatedAtTurnInitiatorMessageIndex);
        }
        return $taskPlan;
    }
}
