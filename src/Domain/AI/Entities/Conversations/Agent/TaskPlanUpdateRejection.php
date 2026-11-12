<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE violation of an `update_task_plan` call: its code (R1–R25; R12 and R13 were deleted and stay
 * unused) and its exact line, composed by the named factory of the code from the offending values. Every line names the
 * offending value and the valid move, so one retry suffices. A rejected call lists its lines in
 * {@see TaskPlanUpdateRejections}.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateRejection extends ValueObject
{
    public const string CODE_ITEMS_NOT_A_LIST = 'R1';
    public const string CODE_ITEMS_EMPTY = 'R2';
    public const string CODE_KEY_MALFORMED = 'R3';
    public const string CODE_KEY_NOT_A_STRING = 'R4';
    public const string CODE_KEY_REPEATED = 'R5';
    public const string CODE_KEY_UNKNOWN = 'R6';
    public const string CODE_ITEM_MOVED = 'R7';
    public const string CODE_ITEMS_LEFT_OUT = 'R8';
    public const string CODE_TEXT_CHANGED = 'R9';
    public const string CODE_NEITHER_KEY_NOR_TEXT = 'R10';
    public const string CODE_NEW_TEXT_REPEATED = 'R11';
    public const string CODE_SEEDED_ITEM_DROPPED = 'R14';
    public const string CODE_REMOVE_WITHOUT_KEY = 'R15';
    public const string CODE_SEEDED_ORDER_CHANGED = 'R16';
    public const string CODE_PLAN_LEFT_EMPTY = 'R17';
    public const string CODE_CONTAINER_LEFT_EMPTY = 'R18';
    public const string CODE_ITEM_NOT_AN_OBJECT = 'R19';
    public const string CODE_SUBTASKS_MALFORMED = 'R20';
    public const string CODE_TEXT_MALFORMED = 'R21';
    public const string CODE_REMOVE_WITH_OTHER_FIELDS = 'R22';
    public const string CODE_REMOVE_NOT_TRUE = 'R23';
    public const string CODE_KEY_NUMBERS_EXHAUSTED = 'R24';
    public const string CODE_STORED_KEYS_INCONSISTENT = 'R25';

    /** How many characters of an item text a line quotes (a seeded text runs to 600). */
    public const int MAX_QUOTED_TEXT_CHARS = 80;

    /** How many characters of a new parent's text names the level it opens (`under the new item '…'`). */
    public const int MAX_PARENT_TEXT_CHARS = 30;

    /** The code, R1–R25. */
    public string $code = '';

    /** The exact line the rejection block lists. */
    public string $line = '';

    public static function ofCodeAndLine(string $code, string $line): static
    {
        $taskPlanUpdateRejection = new static();
        $taskPlanUpdateRejection->code = $code;
        $taskPlanUpdateRejection->line = $line;
        return $taskPlanUpdateRejection;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->code . ':' . $this->line);
    }

    /** `under "<parentKey>"`, or `at the top level` for "" — the `<parent>` of a stored level. */
    public static function describeStoredLevel(string $parentKey): string
    {
        return $parentKey === '' ? 'at the top level' : 'under "' . $parentKey . '"';
    }

    /** `under the new item '<text clipped to 30>'` — the `<parent>` of a level a new item opens. */
    public static function describeNewItemLevel(string $newParentText): string
    {
        return "under the new item '" . self::clip($newParentText, self::MAX_PARENT_TEXT_CHARS) . "'";
    }

    /** An item text as a line quotes it, clipped. */
    public static function quoteText(string $itemText): string
    {
        return self::clip(trim($itemText), self::MAX_QUOTED_TEXT_CHARS);
    }

    protected static function clip(string $text, int $maxChars): string
    {
        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars - 1)) . '…' : $text;
    }

    public static function itemsNotAList(): self
    {
        return self::ofCodeAndLine(self::CODE_ITEMS_NOT_A_LIST, "'items' is missing or not a list — send the plan as items: [...].");
    }

    public static function itemsEmpty(): self
    {
        return self::ofCodeAndLine(self::CODE_ITEMS_EMPTY, "'items' is empty — a plan needs at least one item. To drop items, send them with \"remove\": true; every other item stays listed.");
    }

    /**
     * @param string|null $candidateKey the R3 normalization of the value when it conforms ({@see TaskPlanItem::buildKeyCandidate()})
     * @param bool $candidateNamesAnItem the candidate is a key of the plan
     */
    public static function keyMalformed(string $keyRawJson, ?string $candidateKey, bool $candidateNamesAnItem): self
    {
        $suggestion = '';
        if ($candidateKey !== null) {
            $suggestion = $candidateNamesAnItem ? ' Did you mean "' . $candidateKey . '"?' : ' "' . $candidateKey . '" is not in your plan either.';
        }
        return self::ofCodeAndLine(self::CODE_KEY_MALFORMED, 'Key ' . $keyRawJson . ' is not a key. A key is the number in front of an item in "Your task plan" and nothing else: digits joined by single dots, each number 1–999 without a leading zero, no spaces, no title (1, 1.2, 1.2.3, 1.2.3.1).' . $suggestion);
    }

    public static function keySentAsNumber(string $keyRawJson): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_NOT_A_STRING, 'Key ' . $keyRawJson . ' was sent as a number — send keys as strings, exactly as "Your task plan" shows them (e.g. "key": "2.1"); a number can lose digits (7.10 arrives as 7.1), so take the key from the list below.');
    }

    public static function keySentAsNull(int $position, string $parentLevel): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_NOT_A_STRING, 'Item ' . $position . ' ' . $parentLevel . ' has "key": null — an existing item needs its key as a string ("key": "2.1"); a NEW item leaves "key" out and carries "text".');
    }

    public static function keySentAsBoolean(string $keyRawJson, int $position, string $parentLevel): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_NOT_A_STRING, 'Key ' . $keyRawJson . ' (item ' . $position . ' ' . $parentLevel . ') is not a key — send the item\'s number from "Your task plan" as a string ("key": "2.1").');
    }

    public static function keySentAsListOrObject(string $keyRawJson, int $position, string $parentLevel, bool $isList): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_NOT_A_STRING, 'Key ' . $keyRawJson . ' (item ' . $position . ' ' . $parentLevel . ') is ' . ($isList ? 'a list' : 'an object') . ' — each item carries ONE key, as a string ("key": "2.1").');
    }

    public static function keyRepeated(string $key, int $occurrences): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_REPEATED, 'Key "' . $key . '" appears ' . $occurrences . ' times — each existing item appears exactly once.');
    }

    public static function keyUnknown(string $key): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_UNKNOWN, 'Key "' . $key . '" does not exist in your plan. An existing item is named by its key from the list below; a NEW item carries no key — send it as {"text": "…"} and the platform assigns its key.');
    }

    /**
     * @param string $storedParentKey the key of the item's stored parent ("" = the top level)
     * @param string[] $storedSiblingKeys the keys of that parent's stored subtasks, in order (the item's own included) —
     *        the line shows the nested list that keeps the item where it is
     */
    public static function itemMoved(string $key, string $sentLevel, string $storedLevel, bool $isSeeded, string $storedParentKey = '', array $storedSiblingKeys = []): self
    {
        $line = 'Item "' . $key . '" was sent ' . $sentLevel . ', but it belongs ' . $storedLevel . '. An item never moves: ';
        $line .= $isSeeded
            ? '"' . $key . '" is part of the seeded plan and stays where it is.'
            : 'keep "' . $key . '" where it is; to do this work elsewhere, drop it ({"key": "' . $key . '", "remove": true}) and add a new item there.';
        return self::ofCodeAndLine(self::CODE_ITEM_MOVED, $line . self::buildCallThatKeepsItemInPlace($key, $storedParentKey, $storedSiblingKeys));
    }

    /**
     * The R7 sentence with the call that passes: a subtask is named INSIDE its parent's "subtasks" list (the parent's
     * stored subtasks, a new subtask added to the same list), a top-level item in the top-level list.
     *
     * @param string[] $storedSiblingKeys
     */
    public static function buildCallThatKeepsItemInPlace(string $key, string $storedParentKey, array $storedSiblingKeys): string
    {
        if ($storedParentKey === '') {
            return ' To keep it, list {"key": "' . $key . '"} in the top-level items.';
        }
        $siblingKeys = $storedSiblingKeys !== [] ? $storedSiblingKeys : [$key];
        $subtaskElements = array_map(static fn (string $siblingKey): string => '{"key": "' . $siblingKey . '"}', $siblingKeys);
        return ' To keep it, name it inside its parent: {"key": "' . $storedParentKey . '", "subtasks": [' . implode(', ', $subtaskElements) . ']}'
            . ' — a new item under "' . $storedParentKey . '" goes into that same list as {"text": "…"}.';
    }

    /** The R8 hint for a plan that stands from an earlier task: its items leave only by an explicit drop. */
    public const string EARLIER_TASK_ITEMS_HINT = ' If these items belong to an earlier task, drop them with "remove": true and add the new items in this same call.';

    /**
     * @param string[] $omittedItemDescriptions `<key> '<text>'` with its suffix, one per item left out
     * @param string[] $earlierTaskItemKeys the keys of the left-out items that may belong to an earlier task (top level, not
     *        seeded, not added in this response) — the earlier-task hint applies only to them: {@see EARLIER_TASK_ITEMS_HINT}
     *        when every left-out item is one, the hint naming their keys when the line mixes them with items that stay
     */
    public static function itemsLeftOut(array $omittedItemDescriptions, string $firstOmittedKey, array $earlierTaskItemKeys = []): self
    {
        return self::ofCodeAndLine(self::CODE_ITEMS_LEFT_OUT, 'Your call leaves out ' . implode(', ', $omittedItemDescriptions) . '. Every existing item stays named: send {"key": "' . $firstOmittedKey . '"} to keep it, {"key": "' . $firstOmittedKey . '", "remove": true} to drop it.'
            . self::buildEarlierTaskItemsHint($earlierTaskItemKeys, count($omittedItemDescriptions)));
    }

    /**
     * The earlier-task hint of the R8 line: none without a droppable item, {@see EARLIER_TASK_ITEMS_HINT} when every
     * left-out item is droppable, otherwise the hint naming only the droppable keys (a seeded item or one added in this
     * response is never told to be dropped).
     *
     * @param string[] $earlierTaskItemKeys
     */
    public static function buildEarlierTaskItemsHint(array $earlierTaskItemKeys, int $omittedItemCount): string
    {
        if ($earlierTaskItemKeys === []) {
            return '';
        }
        if (count($earlierTaskItemKeys) === $omittedItemCount) {
            return self::EARLIER_TASK_ITEMS_HINT;
        }
        if (count($earlierTaskItemKeys) === 1) {
            return ' If item ' . $earlierTaskItemKeys[0] . ' belongs to an earlier task, drop it with "remove": true and add the new items in this same call.';
        }
        return ' If items ' . implode(', ', $earlierTaskItemKeys) . ' belong to an earlier task, drop them with "remove": true and add the new items in this same call.';
    }

    public static function textChanged(string $key, string $storedText, string $sentText): self
    {
        return self::ofCodeAndLine(self::CODE_TEXT_CHANGED, 'Item "' . $key . '" reads \'' . self::quoteText($storedText) . '\'; you sent \'' . self::quoteText($sentText) . '\'. An item\'s text is fixed — send {"key": "' . $key . '"} without text. To change what the item asks for, drop "' . $key . '" and add a new item with the new text.');
    }

    public static function textOfAnotherItem(string $key, string $storedText, string $otherKey): self
    {
        return self::ofCodeAndLine(self::CODE_TEXT_CHANGED, 'Item "' . $key . '" reads \'' . self::quoteText($storedText) . '\'; the text you sent belongs to "' . $otherKey . '". Keys never change and do not follow the order — send "' . $otherKey . '" for that item.');
    }

    public static function neitherKeyNorText(int $position, string $parentLevel): self
    {
        return self::ofCodeAndLine(self::CODE_NEITHER_KEY_NOR_TEXT, 'Item ' . $position . ' ' . $parentLevel . ' has neither a key nor a text — an existing item needs "key", a new item needs "text".');
    }

    public static function keySentAsTask(int $position, string $parentLevel, string $sentKey): self
    {
        return self::ofCodeAndLine(self::CODE_NEITHER_KEY_NOR_TEXT, 'Item ' . $position . ' ' . $parentLevel . ' has "task": "' . $sentKey . '" — an existing item is named by "key", not "task": send {"key": "' . $sentKey . '"}; a new item states its task in "text".');
    }

    public static function newTextRepeatsAnItem(string $newText, string $parentLevel, string $existingKey, string $existingText): self
    {
        return self::ofCodeAndLine(self::CODE_NEW_TEXT_REPEATED, 'New item \'' . self::quoteText($newText) . '\' ' . $parentLevel . ' repeats "' . $existingKey . '" \'' . self::quoteText($existingText) . '\' — keep "' . $existingKey . '" with {"key": "' . $existingKey . '"}; a new item needs a different text.');
    }

    public static function newTextsRepeatEachOther(int $firstPosition, int $secondPosition, string $parentLevel, string $newText): self
    {
        return self::ofCodeAndLine(self::CODE_NEW_TEXT_REPEATED, 'New items ' . $firstPosition . ' and ' . $secondPosition . ' ' . $parentLevel . ' both read \'' . self::quoteText($newText) . '\' — send it once.');
    }

    public static function seededItemDropped(string $key): self
    {
        return self::ofCodeAndLine(self::CODE_SEEDED_ITEM_DROPPED, '"' . $key . '" is part of the seeded plan and cannot be dropped — close it with set_task_status: not_applicable and the reason it does not apply.');
    }

    public static function seededItemDroppedWithItsParent(string $key, string $droppedParentKey): self
    {
        return self::ofCodeAndLine(self::CODE_SEEDED_ITEM_DROPPED, '"' . $key . '" is part of the seeded plan and cannot be dropped; dropping "' . $droppedParentKey . '" would drop it.');
    }

    public static function removeWithoutKey(): self
    {
        return self::ofCodeAndLine(self::CODE_REMOVE_WITHOUT_KEY, '"remove": true needs the key of an existing item — a new item is simply left out.');
    }

    public static function seededOrderChanged(string $earlierKey, string $laterKey): self
    {
        return self::ofCodeAndLine(self::CODE_SEEDED_ORDER_CHANGED, 'Seeded items keep their order: "' . $earlierKey . '" stays before "' . $laterKey . '".');
    }

    public static function planLeftEmpty(): self
    {
        return self::ofCodeAndLine(self::CODE_PLAN_LEFT_EMPTY, 'This call would leave your plan without any item — a plan always holds at least one. To end the task, close the open items with set_task_status (not_applicable with the reason, e.g. "the owner cancelled it"); to start a different task, drop the old items and add the new ones in this same call.');
    }

    public static function containerLeftEmpty(string $key, string $text): self
    {
        return self::ofCodeAndLine(self::CODE_CONTAINER_LEFT_EMPTY, 'This call drops every subtask of "' . $key . '" \'' . self::quoteText($text) . '\' — an item that has subtasks keeps at least one. Drop "' . $key . '" itself ({"key": "' . $key . '", "remove": true}), keep one of its subtasks, or close the subtasks with set_task_status (not_applicable with the reason).');
    }

    public static function itemNotAnObject(int $position, string $parentLevel, string $nonObjectDescription): self
    {
        return self::ofCodeAndLine(self::CODE_ITEM_NOT_AN_OBJECT, 'Item ' . $position . ' ' . $parentLevel . ' is ' . $nonObjectDescription . ', not an object — send each item as {"key": "…"} (existing) or {"text": "…"} (new).');
    }

    /** @param string $itemLabel `"<key>"`, `the new item '<text>'` or `item <position> <parent>` */
    public static function subtasksMalformed(string $itemLabel, string $subtasksRawJson): self
    {
        return self::ofCodeAndLine(self::CODE_SUBTASKS_MALFORMED, '"subtasks" of ' . $itemLabel . ' is ' . $subtasksRawJson . ' — send a non-empty list of items, or leave "subtasks" out to keep the existing subtasks unchanged.');
    }

    public static function textEmpty(int $position, string $parentLevel): self
    {
        return self::ofCodeAndLine(self::CODE_TEXT_MALFORMED, 'Item ' . $position . ' ' . $parentLevel . ' has an empty "text" — a new item states its task in "text" as a non-empty string; an existing item needs no text.');
    }

    public static function textNotAString(int $position, string $parentLevel, string $textRawJson): self
    {
        return self::ofCodeAndLine(self::CODE_TEXT_MALFORMED, 'Item ' . $position . ' ' . $parentLevel . ' has "text": ' . $textRawJson . ' — a new item states its task in "text" as a non-empty string; an existing item needs no text.');
    }

    /** @param string[] $otherFieldNames */
    public static function removeWithOtherFields(string $key, array $otherFieldNames): self
    {
        return self::ofCodeAndLine(self::CODE_REMOVE_WITH_OTHER_FIELDS, '{"key": "' . $key . '", "remove": true} drops the item, so it takes no other field (you also sent ' . implode(', ', $otherFieldNames) . '). Send it alone to drop "' . $key . '", or without "remove" to keep it.');
    }

    /** @param string $itemLabel `"<key>"`, or `the new item '<text>'` when no key was sent */
    public static function removeNotTrue(string $itemLabel, string $removeRawJson): self
    {
        return self::ofCodeAndLine(self::CODE_REMOVE_NOT_TRUE, '"remove" of ' . $itemLabel . ' is ' . $removeRawJson . ' — only "remove": true exists; leave "remove" out to keep the item.');
    }

    public static function keyNumbersExhausted(string $parentLevel): self
    {
        return self::ofCodeAndLine(self::CODE_KEY_NUMBERS_EXHAUSTED, 'No new item fits ' . $parentLevel . ': this level has used all ' . TaskPlanItem::KEY_SEGMENT_MAX . ' numbers (a number is never reused). Add the item under another parent, or close this plan with set_task_status and start a new one.');
    }

    /** @param string[] $violationLines {@see TaskPlanKeyIntegrityViolations::$lines} */
    public static function storedKeysInconsistent(array $violationLines): self
    {
        return self::ofCodeAndLine(self::CODE_STORED_KEYS_INCONSISTENT, 'Your stored plan has an inconsistency the platform must fix (' . implode('; ', $violationLines) . ') — structure changes are paused. Keep working with set_task_status on the keys listed below; the platform has logged the defect.');
    }
}
