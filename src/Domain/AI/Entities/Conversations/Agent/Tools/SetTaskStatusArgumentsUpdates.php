<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The `updates` list of one `set_task_status` call, in the order the model sent it. Identity of an
 * element is its position ({@see SetTaskStatusArgumentsUpdate::$position}), so two updates with the same content stay two
 * updates.
 *
 * @property SetTaskStatusArgumentsUpdate[] $elements
 * @method SetTaskStatusArgumentsUpdate[] getElements()
 * @phpstan-consistent-constructor
 */
class SetTaskStatusArgumentsUpdates extends ObjectSet
{
    /**
     * Read a raw `updates` value that decoded as a PHP array — its values in order, each at its 0-based position.
     *
     * @param array<array-key, mixed> $rawUpdates
     */
    public static function fromRawUpdates(array $rawUpdates): static
    {
        $setTaskStatusArgumentsUpdates = new static();
        foreach (array_values($rawUpdates) as $position => $rawUpdate) {
            // ObjectSet::add() is BY-REFERENCE — pass a variable, never a method return
            $setTaskStatusArgumentsUpdate = SetTaskStatusArgumentsUpdate::fromRawUpdate($rawUpdate, $position);
            $setTaskStatusArgumentsUpdates->add($setTaskStatusArgumentsUpdate);
        }
        return $setTaskStatusArgumentsUpdates;
    }
}
