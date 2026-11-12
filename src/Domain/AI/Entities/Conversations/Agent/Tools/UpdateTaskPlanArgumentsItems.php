<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * One `items` / `subtasks` list of an `update_task_plan` call, in the order the model sent it. Identity
 * of an element is its request path ({@see UpdateTaskPlanArgumentsItem::$requestPath}), so two elements with the same
 * content stay two elements — the check names both (R5, R11).
 *
 * @property UpdateTaskPlanArgumentsItem[] $elements
 * @method UpdateTaskPlanArgumentsItem[] getElements()
 * @phpstan-consistent-constructor
 */
class UpdateTaskPlanArgumentsItems extends ObjectSet
{
    /**
     * Read a raw JSON list, each element at its 1-based position below `$parentRequestPath` ("" = the top level).
     *
     * @param array<int, mixed> $rawList
     */
    public static function fromRawList(array $rawList, string $parentRequestPath = ''): static
    {
        $argumentsItems = new static();
        foreach (array_values($rawList) as $index => $rawElement) {
            // ObjectSet::add() is BY-REFERENCE — pass a variable, never a method return
            $argumentsItem = UpdateTaskPlanArgumentsItem::fromRawElement($rawElement, $index + 1, $parentRequestPath);
            $argumentsItems->add($argumentsItem);
        }
        return $argumentsItems;
    }

    /** At least one element is a JSON object — a list of strings, numbers or lists is named by R19 alone. */
    public function hasObjectElement(): bool
    {
        foreach ($this->getElements() as $argumentsItem) {
            if ($argumentsItem->isObject) {
                return true;
            }
        }
        return false;
    }
}
