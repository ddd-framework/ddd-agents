<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE element of a `set_task_status` call's `updates` list, read from the wire once: the address as
 * the model sent it and as a trimmed string, the status before and after the alias normalization, the status note and
 * the two blocked flags. The field aliases a weak model produces are read here, in one place (`task` / `item` / `number`,
 * `status` / `state`, the legacy note names, `requiresUserAction` / `requires_user_action`). Nothing is judged here: the
 * strict address check ({@see SetTaskStatusAddressCheck}) and the gates of the tick
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan::applyStatusUpdates()}) read it. A working value of one
 * call: built by {@see SetTaskStatusArguments::fromToolArguments()}, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class SetTaskStatusArgumentsUpdate extends ValueObject
{
    /** 0-based position within the call's `updates` list — the address check's index. */
    public int $position = 0;

    /** The element decoded as a PHP array (a JSON object, or a list, which carries no field), so its fields can be read. */
    public bool $sentAsArray = false;

    /**
     * @var string|int|float|bool|array<array-key, mixed>|null The task address exactly as the model sent it, through the
     *      alias chain (`task` / `item` / `number`) — what the strict address check judges; null when no alias carries one
     */
    public string|int|float|bool|array|null $rawTaskAddress = null;

    /** The task address as a trimmed string ('' for a list or object address) — what an earlier call of the turn is compared by. */
    public string $taskAddress = '';

    /** The sent status lower-cased and trimmed, before the alias normalization (`status` / `state`). */
    public string $rawStatus = '';

    /** The status after the alias normalization ({@see UpdateTaskPlanTool::STATUS_ALIASES}); null when it is not recognized. */
    public ?string $status = null;

    /** The status note through every accepted legacy alias, trimmed ('' when none was sent). */
    public string $statusNote = '';

    /** The sent `requiresUserAction` (or `requires_user_action`), false when not sent. */
    public bool $requiresUserAction = false;

    /** The sent `retryable`, false when not sent. */
    public bool $retryable = false;

    /**
     * Read ONE raw element of `updates` at the 0-based `$position`. A JSON list where a string belongs reads as PHP's
     * string conversion of an array would ("array" for the status, "Array" for the note), without the conversion's warning.
     */
    public static function fromRawUpdate(mixed $rawUpdate, int $position): static
    {
        $setTaskStatusArgumentsUpdate = new static();
        $setTaskStatusArgumentsUpdate->position = $position;
        if (!is_array($rawUpdate)) {
            return $setTaskStatusArgumentsUpdate;
        }
        $setTaskStatusArgumentsUpdate->sentAsArray = true;
        $setTaskStatusArgumentsUpdate->rawTaskAddress = $rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null;
        $setTaskStatusArgumentsUpdate->taskAddress = self::readTaskAddress($rawUpdate);
        $setTaskStatusArgumentsUpdate->rawStatus = strtolower(trim(self::convertToString($rawUpdate['status'] ?? $rawUpdate['state'] ?? '')));
        $setTaskStatusArgumentsUpdate->status = UpdateTaskPlanTool::STATUS_ALIASES[$setTaskStatusArgumentsUpdate->rawStatus] ?? null;
        // statusNote unified the former completionNote + blockReason — every legacy alias is still read
        $setTaskStatusArgumentsUpdate->statusNote = trim(self::convertToString(
            $rawUpdate['statusNote'] ?? $rawUpdate['status_note']
            ?? $rawUpdate['blockReason'] ?? $rawUpdate['block_reason'] ?? $rawUpdate['reason']
            ?? $rawUpdate['completionNote'] ?? $rawUpdate['completion_note'] ?? $rawUpdate['comment'] ?? $rawUpdate['note'] ?? $rawUpdate['result'] ?? ''
        ));
        $setTaskStatusArgumentsUpdate->requiresUserAction = (bool)($rawUpdate['requiresUserAction'] ?? $rawUpdate['requires_user_action'] ?? false);
        $setTaskStatusArgumentsUpdate->retryable = (bool)($rawUpdate['retryable'] ?? false);
        return $setTaskStatusArgumentsUpdate;
    }

    /**
     * The update an `update_task_plan` element asks of its item's status (the resend's statuses pass the
     * tick application): addressed by the item's key, at the 0-based `$position` among the call's status updates, with
     * the element's status (lower-cased and trimmed as sent), its note and its flags (not sent = false, as on a tick).
     */
    public static function forStatusOfResendElement(UpdateTaskPlanArgumentsItem $argumentsItem, string $itemKey, int $position): static
    {
        $setTaskStatusArgumentsUpdate = new static();
        $setTaskStatusArgumentsUpdate->position = $position;
        $setTaskStatusArgumentsUpdate->sentAsArray = true;
        $setTaskStatusArgumentsUpdate->rawTaskAddress = $itemKey;
        $setTaskStatusArgumentsUpdate->taskAddress = $itemKey;
        $setTaskStatusArgumentsUpdate->rawStatus = $argumentsItem->rawStatus;
        $setTaskStatusArgumentsUpdate->status = UpdateTaskPlanTool::STATUS_ALIASES[$argumentsItem->rawStatus] ?? null;
        $setTaskStatusArgumentsUpdate->statusNote = $argumentsItem->statusNote;
        $setTaskStatusArgumentsUpdate->requiresUserAction = (bool)($argumentsItem->requiresUserAction ?? false);
        $setTaskStatusArgumentsUpdate->retryable = (bool)($argumentsItem->retryable ?? false);
        return $setTaskStatusArgumentsUpdate;
    }

    /**
     * The task ADDRESS of one raw update as a trimmed string — the alias chain (`task` / `item` / `number`) in ONE place.
     * A list or object address reads as empty.
     *
     * @param array<array-key, mixed> $rawUpdate
     */
    public static function readTaskAddress(array $rawUpdate): string
    {
        $rawTaskAddress = $rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null;
        return is_array($rawTaskAddress) || is_object($rawTaskAddress) ? '' : trim((string)$rawTaskAddress);
    }

    /**
     * The address as the feedback names the item: the sent value as a string. Read only for an update the strict address
     * check accepted, whose address is a key string.
     */
    public function getTaskAddressAsSent(): string
    {
        return is_array($this->rawTaskAddress) ? '' : (string)$this->rawTaskAddress;
    }

    /** A decoded JSON value as PHP's string conversion reads it; a list reads `Array`. */
    protected static function convertToString(mixed $rawValue): string
    {
        return is_array($rawValue) ? 'Array' : (string)$rawValue;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->position);
    }
}
