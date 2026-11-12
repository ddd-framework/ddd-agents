<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of ONE `update_task_plan` call, parsed from the wire ONCE: the `items`
 * list with every element read into an {@see UpdateTaskPlanArgumentsItem} (which fields were sent, with which JSON
 * type). Nothing is truncated, capped or dropped here (no item limit, any depth); a
 * malformed element stays in the list for the check to name ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan::checkUpdate()}).
 *
 * @phpstan-consistent-constructor
 */
class UpdateTaskPlanArguments extends ValueObject
{
    /** `items` was sent as a JSON list (R1 otherwise). */
    public bool $itemsIsList = false;

    /** The top-level list; null when `items` is missing or not a list. */
    public ?UpdateTaskPlanArgumentsItems $items = null;

    /** @param array<string, mixed> $arguments the call's decoded arguments */
    public static function fromArguments(array $arguments): static
    {
        $updateTaskPlanArguments = new static();
        $rawItems = $arguments['items'] ?? null;
        if (is_array($rawItems) && array_is_list($rawItems)) {
            $updateTaskPlanArguments->itemsIsList = true;
            $updateTaskPlanArguments->items = UpdateTaskPlanArgumentsItems::fromRawList($rawItems);
        }
        return $updateTaskPlanArguments;
    }

    /** `items` is a list without an element (R2). */
    public function hasNoItems(): bool
    {
        return $this->itemsIsList && ($this->items === null || $this->items->count() === 0);
    }
}
