<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The address pre-pass of ONE `set_task_status` call (strict): every update's
 * `task` is resolved before any update applies. Only a JSON string that conforms to {@see TaskPlanItem::KEY_PATTERN} and
 * names exactly one item of the standing plan addresses an item; anything else — a key with a title behind it, an item
 * text, a malformed or zero-padded string, a JSON number / boolean / null / list / object, a missing `task`, an unknown
 * key, a key a stored duplicate makes ambiguous — rejects the WHOLE call, and the rejection names each offending value
 * with the key to send and lists the open keys with their texts, so one retry suffices. The normalization behind the
 * "send …" hint is never applied. A working value of one call: read it through its methods.
 *
 * @phpstan-consistent-constructor
 */
class SetTaskStatusAddressCheck extends ValueObject
{
    /** The first line of a rejected call's result. */
    public const string REJECTION_HEADER = 'Task status NOT updated — nothing changed. Fix every line below and send the call again:';

    /** How many characters of an offending raw JSON value a rejection line quotes. */
    public const int MAX_QUOTED_JSON_CHARS = 40;

    /** How many characters of an offending string a rejection line quotes (a copied seed text runs to 600). */
    public const int MAX_QUOTED_STRING_CHARS = 80;

    /** How many characters of an open item's text the key list shows. */
    public const int MAX_KEY_LIST_TEXT_CHARS = 50;

    /** @var TaskPlanItem[] 0-based update position → the item its key names */
    protected array $itemsByUpdatePosition = [];

    /** @var string[] One line per offending update, in update order. */
    protected array $rejectionLines = [];

    /** @var string[] The conforming keys that name more than one stored item (a key-integrity violation). */
    protected array $ambiguousKeys = [];

    /**
     * Resolve every update of the call against the standing plan.
     *
     * @param array<int|string, mixed> $rawUpdates the call's `updates`, in order
     */
    public static function ofUpdates(array $rawUpdates, ?TaskPlanItems $taskPlanItems): self
    {
        return self::ofArguments(SetTaskStatusArguments::fromToolArguments(['updates' => $rawUpdates]), $taskPlanItems);
    }

    /** Resolve every update of the parsed call against the standing plan, in the order sent. */
    public static function ofArguments(SetTaskStatusArguments $setTaskStatusArguments, ?TaskPlanItems $taskPlanItems): static
    {
        $setTaskStatusAddressCheck = new static();
        foreach ($setTaskStatusArguments->updates->getElements() as $setTaskStatusArgumentsUpdate) {
            $setTaskStatusAddressCheck->checkUpdate($setTaskStatusArgumentsUpdate, $taskPlanItems);
        }
        return $setTaskStatusAddressCheck;
    }

    /** Whether any update failed its address — nothing of the call may apply. */
    public function rejectsTheCall(): bool
    {
        return $this->rejectionLines !== [];
    }

    /** The item the update at the 0-based `$position` names, or null when its address failed. */
    public function getItemOfUpdate(int $position): ?TaskPlanItem
    {
        return $this->itemsByUpdatePosition[$position] ?? null;
    }

    /** @return string[] the rejection lines, one per offending update */
    public function getRejectionLines(): array
    {
        return $this->rejectionLines;
    }

    /** @return string[] the keys that name more than one stored item */
    public function getAmbiguousKeys(): array
    {
        return $this->ambiguousKeys;
    }

    /**
     * The result text of the rejected call: the header, one line per offending update, then the open keys with their
     * texts (`Your open items: 2.2 'Draft and post the replies' · 3.1 'Draft the post'`).
     */
    public function renderRejection(?TaskPlanItems $taskPlanItems): string
    {
        $openItemKeyLines = $taskPlanItems?->getOpenItemKeyLines(self::MAX_KEY_LIST_TEXT_CHARS) ?? [];
        return self::REJECTION_HEADER . "\n"
            . implode("\n", array_map(static fn (string $rejectionLine): string => '- ' . $rejectionLine, $this->rejectionLines)) . "\n"
            . 'Your open items: ' . ($openItemKeyLines !== [] ? implode(' · ', $openItemKeyLines) : 'none.');
    }

    /** One update: its `task` (through the `task` / `item` / `number` aliases) resolved, or its rejection line recorded. */
    protected function checkUpdate(SetTaskStatusArgumentsUpdate $setTaskStatusArgumentsUpdate, ?TaskPlanItems $taskPlanItems): void
    {
        $position = $setTaskStatusArgumentsUpdate->position;
        $updateNumber = $position + 1;
        $rawTaskAddress = $setTaskStatusArgumentsUpdate->sentAsArray ? $setTaskStatusArgumentsUpdate->rawTaskAddress : null;
        if ($rawTaskAddress === null || (is_string($rawTaskAddress) && trim($rawTaskAddress) === '')) {
            $this->rejectionLines[] = 'Update ' . $updateNumber . ' has no "task" — send the item\'s number from "Your task plan" as a string.';
            return;
        }
        if (is_bool($rawTaskAddress)) {
            // the example is an open key of THIS plan — a fixed example key would be copied as an address
            $openKeyExample = self::findFirstOpenKey($taskPlanItems);
            $this->rejectionLines[] = 'Task ' . ($rawTaskAddress ? 'true' : 'false') . ' was sent as a boolean — send the item\'s number from "Your task plan" as a string'
                . ($openKeyExample !== null ? ' ("task": "' . $openKeyExample . '").' : '.');
            return;
        }
        if (is_int($rawTaskAddress) || is_float($rawTaskAddress)) {
            $rawJson = self::quoteRawJson($rawTaskAddress);
            // only an integer names a key exactly — a decimal arrives already rounded (7.10 as 7.1), so a key read from
            // it may be another item than the one the model meant
            $candidateKey = is_int($rawTaskAddress) ? self::findExistingKeyOfInteger($rawTaskAddress, $taskPlanItems) : null;
            $this->rejectionLines[] = 'Task ' . $rawJson . ' was sent as a number — '
                . ($candidateKey !== null ? 'send "task": "' . $candidateKey . '".' : 'send the key as a string, exactly as "Your task plan" shows it.');
            return;
        }
        if (!is_string($rawTaskAddress)) {
            $this->rejectionLines[] = 'Task ' . self::quoteRawJson($rawTaskAddress) . ' (update ' . $updateNumber . ') is '
                . (array_is_list($rawTaskAddress) ? 'a list' : 'an object')
                . ' — each update names ONE item by its number, as a string.';
            return;
        }
        if (TaskPlanItem::isConformingKey($rawTaskAddress)) {
            // the one item the key names — null for an unknown key and for a key a stored duplicate makes ambiguous
            $taskPlanItem = $taskPlanItems?->getItemByKey($rawTaskAddress);
            if ($taskPlanItem !== null) {
                $this->itemsByUpdatePosition[$position] = $taskPlanItem;
                return;
            }
            if (($taskPlanItems?->countItemsWithKey($rawTaskAddress) ?? 0) > 1) {
                $this->ambiguousKeys[] = $rawTaskAddress;
                $this->rejectionLines[] = "Task '{$rawTaskAddress}' names more than one item in your stored plan — the platform has logged the defect; use another key.";
                return;
            }
            $this->rejectionLines[] = "Task '{$rawTaskAddress}' does not exist in your plan.";
            return;
        }
        $this->rejectionLines[] = self::describeNonConformingString($rawTaskAddress, self::findCandidateKey($rawTaskAddress, $taskPlanItems));
    }

    /** The line for a string that is not a key: a key with text behind it, a text, a zero-padded or otherwise malformed number. */
    protected static function describeNonConformingString(string $rawTaskAddress, ?string $candidateKey): string
    {
        $quotedValue = self::quoteString($rawTaskAddress);
        $suggestion = $candidateKey !== null ? '; send "task": "' . $candidateKey . '".' : '.';
        if (preg_match('/^\s*\d+(?:\.\d+)*\.?\s+\S/u', $rawTaskAddress) === 1) {
            // the number with a title copied behind it — the hint only when the number names an item of this plan
            return $candidateKey !== null
                ? "Task '{$quotedValue}' is not a key — send \"task\": \"{$candidateKey}\" (the number only, as a string)."
                : "Task '{$quotedValue}' is not a key — address an item by its number from \"Your task plan\".";
        }
        if (preg_match('/\p{L}/u', $rawTaskAddress) === 1) {
            return "Task '{$quotedValue}' is not a key — address an item by its number from \"Your task plan\".";
        }
        if (preg_match('/^\d+(?:\.\d+)*$/', $rawTaskAddress) === 1 && preg_match('/(?:^|\.)0/', $rawTaskAddress) === 1) {
            return "Task '{$quotedValue}' is not a key — a number never starts with 0" . $suggestion;
        }
        return "Task '{$quotedValue}' is not a key — the number only, digits joined by single dots" . $suggestion;
    }

    /**
     * The key a value most likely meant (the R3 normalization: trimmed, cut at the first space, `-` and `/` as
     * dots, a trailing dot and leading zeros dropped) — only when that key names exactly one item of the plan. Never applied.
     */
    protected static function findCandidateKey(string $value, ?TaskPlanItems $taskPlanItems): ?string
    {
        if ($taskPlanItems === null) {
            return null;
        }
        $candidate = TaskPlanItem::buildKeyCandidate($value);
        if ($candidate === null || $taskPlanItems->countItemsWithKey($candidate) !== 1) {
            return null;
        }
        return $candidate;
    }

    /** The key an integer JSON number names when that key addresses exactly one item of the plan, else null. */
    protected static function findExistingKeyOfInteger(int $rawTaskAddress, ?TaskPlanItems $taskPlanItems): ?string
    {
        $candidateKey = (string)$rawTaskAddress;
        if ($taskPlanItems === null || !TaskPlanItem::isConformingKey($candidateKey) || $taskPlanItems->countItemsWithKey($candidateKey) !== 1) {
            return null;
        }
        return $candidateKey;
    }

    /** The first open leaf's key that addresses exactly one item of the plan, or null when none is open. */
    protected static function findFirstOpenKey(?TaskPlanItems $taskPlanItems): ?string
    {
        foreach ($taskPlanItems?->getOpenItemNumberPaths() ?? [] as $openItemKey) {
            if ($taskPlanItems->countItemsWithKey($openItemKey) === 1) {
                return $openItemKey;
            }
        }
        return null;
    }

    /** A non-string value as the model sent it, as JSON, clipped. */
    protected static function quoteRawJson(mixed $value): string
    {
        $rawJson = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($rawJson) > self::MAX_QUOTED_JSON_CHARS ? mb_substr($rawJson, 0, self::MAX_QUOTED_JSON_CHARS - 1) . '…' : $rawJson;
    }

    /** A string value as the model sent it, clipped. */
    protected static function quoteString(string $value): string
    {
        return mb_strlen($value) > self::MAX_QUOTED_STRING_CHARS ? mb_substr($value, 0, self::MAX_QUOTED_STRING_CHARS - 1) . '…' : $value;
    }
}
