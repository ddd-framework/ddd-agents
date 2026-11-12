<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of ONE `set_task_status` call, parsed from the wire ONCE (the twin of
 * {@see UpdateTaskPlanArguments}): whether `updates` was sent as a list the tick can read, and every element read into a
 * {@see SetTaskStatusArgumentsUpdate}. Nothing is dropped here: an element that is no object stays in the list for the
 * address check to name ({@see SetTaskStatusAddressCheck::ofArguments()}). Read by the tick
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan::applyStatusUpdates()}), the record service, the supervisor
 * replay and the same-turn echo check ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::hasTaskPlanTickNamingTask()}).
 *
 * @phpstan-consistent-constructor
 */
class SetTaskStatusArguments extends ValueObject
{
    /** `updates` decoded as a PHP array (a JSON list or object); false = missing or another JSON type — nothing can apply. */
    public bool $hasUpdatesList = false;

    /** The updates in the order sent; empty when {@see $hasUpdatesList} is false. */
    public SetTaskStatusArgumentsUpdates $updates;

    /** @param array<string, mixed> $arguments the call's decoded arguments */
    public static function fromToolArguments(array $arguments): static
    {
        $setTaskStatusArguments = new static();
        $rawUpdates = $arguments['updates'] ?? null;
        if (is_array($rawUpdates)) {
            $setTaskStatusArguments->hasUpdatesList = true;
            $setTaskStatusArguments->updates = SetTaskStatusArgumentsUpdates::fromRawUpdates($rawUpdates);
        } else {
            $setTaskStatusArguments->updates = new SetTaskStatusArgumentsUpdates();
        }
        return $setTaskStatusArguments;
    }

    /**
     * The status transitions of an `update_task_plan` call as the tick reads them: a list the tick can read,
     * one update per element whose status leaves the stored one ({@see SetTaskStatusArgumentsUpdate::forStatusOfResendElement()}).
     */
    public static function fromUpdates(SetTaskStatusArgumentsUpdates $setTaskStatusArgumentsUpdates): static
    {
        $setTaskStatusArguments = new static();
        $setTaskStatusArguments->hasUpdatesList = true;
        $setTaskStatusArguments->updates = $setTaskStatusArgumentsUpdates;
        return $setTaskStatusArguments;
    }

    /** How many updates the call sent, objects or not. */
    public function countUpdates(): int
    {
        return $this->updates->count();
    }

    /** Whether one update of the call, sent as an object, names `$taskAddress` (its trimmed address equals it). */
    public function hasUpdateNamingTaskAddress(string $taskAddress): bool
    {
        foreach ($this->updates->getElements() as $setTaskStatusArgumentsUpdate) {
            if ($setTaskStatusArgumentsUpdate->sentAsArray && $setTaskStatusArgumentsUpdate->taskAddress === $taskAddress) {
                return true;
            }
        }
        return false;
    }
}
