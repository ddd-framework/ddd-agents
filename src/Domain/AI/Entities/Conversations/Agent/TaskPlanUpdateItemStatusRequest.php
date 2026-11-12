<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArgumentsItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE element of an accepted `update_task_plan` call asks of its item's status fields: the item
 * (kept or new) and the element that sent the fields. A working value of {@see TaskPlanUpdateApplication}; holds
 * references into the tree, never serialized.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateItemStatusRequest extends ValueObject
{
    public ?TaskPlanItem $taskPlanItem = null;

    public ?UpdateTaskPlanArgumentsItem $argumentsItem = null;

    public static function forItem(TaskPlanItem $taskPlanItem, UpdateTaskPlanArgumentsItem $argumentsItem): static
    {
        $taskPlanUpdateItemStatusRequest = new static();
        $taskPlanUpdateItemStatusRequest->taskPlanItem = $taskPlanItem;
        $taskPlanUpdateItemStatusRequest->argumentsItem = $argumentsItem;
        return $taskPlanUpdateItemStatusRequest;
    }
}
