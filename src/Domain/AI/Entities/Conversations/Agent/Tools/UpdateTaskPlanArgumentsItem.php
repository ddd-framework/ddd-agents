<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE element of an `update_task_plan` call's `items` / `subtasks` list, read from the wire once:
 * which fields were SENT and what JSON type each carried — the update check names a wrong type exactly (R4, R19–R23),
 * and the field-presence rules tell an omitted field from a sent one. Nothing is normalized away: a key keeps
 * its raw bytes, a text is only trimmed. The field aliases a weak model produces are read here, in one place (`text` /
 * `task` / `title`, `status` / `state`, the legacy note names, `subtasks` / `sub_tasks` / `children`). A working value of
 * one call: built by {@see UpdateTaskPlanArgumentsItems::fromRawList()}, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class UpdateTaskPlanArgumentsItem extends ValueObject
{
    public const string JSON_TYPE_STRING = 'string';
    public const string JSON_TYPE_NUMBER = 'number';
    public const string JSON_TYPE_BOOLEAN = 'boolean';
    public const string JSON_TYPE_NULL = 'null';
    public const string JSON_TYPE_LIST = 'list';
    public const string JSON_TYPE_OBJECT = 'object';

    /** How many characters of a raw JSON value a rejection line quotes. */
    public const int MAX_QUOTED_JSON_CHARS = 40;

    /** The text field names, in the order they are read. */
    public const array TEXT_FIELD_NAMES = ['text', 'task', 'title'];

    /** The status field names, in the order they are read. */
    public const array STATUS_FIELD_NAMES = ['status', 'state'];

    /** The status-note field names (statusNote unified the former completionNote + blockReason), in the order they are read. */
    public const array STATUS_NOTE_FIELD_NAMES = ['statusNote', 'status_note', 'blockReason', 'block_reason', 'reason', 'completionNote', 'completion_note', 'comment', 'note', 'result'];

    /** The requiresUserAction field names. */
    public const array REQUIRES_USER_ACTION_FIELD_NAMES = ['requiresUserAction', 'requires_user_action'];

    /** The subtask field names, in the order they are read. */
    public const array SUBTASKS_FIELD_NAMES = ['subtasks', 'sub_tasks', 'children'];

    /** 1-based position within its list. */
    public int $position = 1;

    /** The positions from the root down, joined by a dot ("2.3") — the element's identity within the call, never a key. */
    public string $requestPath = '';

    /** The element is a JSON object (a non-list array; `{}` decodes as an empty array and counts as an object). */
    public bool $isObject = true;

    /** For a non-object element: `a string`, `a number`, `a boolean`, `null` or `a list` (R19). */
    public string $nonObjectDescription = '';

    /** A `key` field was sent — also `"key": null`. */
    public bool $hasKeyField = false;

    /** The `key` exactly as sent when it is a JSON string; null for every other JSON type. */
    public ?string $key = null;

    /** The JSON type of the sent `key` ({@see JSON_TYPE_STRING} …). */
    public ?string $keyJsonType = null;

    /** The sent `key` as JSON, clipped ({@see MAX_QUOTED_JSON_CHARS}). */
    public string $keyRawJson = '';

    /** A text field was sent ({@see TEXT_FIELD_NAMES}). */
    public bool $hasTextField = false;

    /** The sent text, trimmed, when it is a JSON string; null for every other JSON type. */
    public ?string $text = null;

    /** The sent text as JSON, clipped. */
    public string $textRawJson = '';

    /** The text field name the text was read from ({@see TEXT_FIELD_NAMES}); null when none was sent. */
    public ?string $textFieldName = null;

    /** A status field was sent ({@see STATUS_FIELD_NAMES}). */
    public bool $hasStatusField = false;

    /** The sent status lower-cased and trimmed, before the alias normalization. */
    public string $rawStatus = '';

    /** A status-note field was sent ({@see STATUS_NOTE_FIELD_NAMES}). */
    public bool $hasStatusNoteField = false;

    /** The sent status note, trimmed ('' when none was sent). */
    public string $statusNote = '';

    /** The sent `requiresUserAction`; null when not sent. */
    public ?bool $requiresUserAction = null;

    /** The sent `retryable`; null when not sent. */
    public ?bool $retryable = null;

    /** A `remove` field was sent. */
    public bool $hasRemoveField = false;

    /** The sent `remove` is exactly JSON `true`. */
    public bool $removeIsTrue = false;

    /** The sent `remove` as JSON, clipped. */
    public string $removeRawJson = '';

    /** A subtask field was sent ({@see SUBTASKS_FIELD_NAMES}). */
    public bool $hasSubtasksField = false;

    /** The sent subtasks when they form a non-empty JSON list; null otherwise. */
    public ?UpdateTaskPlanArgumentsItems $subtasks = null;

    /** The sent subtasks as JSON, clipped. */
    public string $subtasksRawJson = '';

    /** @var string[] The field names sent besides `key` and `remove`, as sent — what a removal must not carry (R22). */
    public array $fieldNamesBesidesKeyAndRemove = [];

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->requestPath);
    }

    /** Read ONE raw element of an `items` / `subtasks` list at the 1-based `$position` below `$parentRequestPath` ("" = the top). */
    public static function fromRawElement(mixed $rawElement, int $position, string $parentRequestPath): static
    {
        $argumentsItem = new static();
        $argumentsItem->position = $position;
        $argumentsItem->requestPath = ($parentRequestPath === '' ? '' : $parentRequestPath . '.') . $position;
        if (!self::isJsonObject($rawElement)) {
            $argumentsItem->isObject = false;
            $argumentsItem->nonObjectDescription = self::describeNonObject($rawElement);
            return $argumentsItem;
        }
        /** @var array<string, mixed> $rawElement */
        if (array_key_exists('key', $rawElement)) {
            $argumentsItem->hasKeyField = true;
            $argumentsItem->keyJsonType = self::jsonTypeOf($rawElement['key']);
            $argumentsItem->key = is_string($rawElement['key']) ? $rawElement['key'] : null;
            $argumentsItem->keyRawJson = self::quoteRawJson($rawElement['key']);
        }
        $textFieldName = self::findFirstSentFieldName($rawElement, self::TEXT_FIELD_NAMES);
        if ($textFieldName !== null) {
            $argumentsItem->hasTextField = true;
            $argumentsItem->textFieldName = $textFieldName;
            $argumentsItem->text = is_string($rawElement[$textFieldName]) ? trim($rawElement[$textFieldName]) : null;
            $argumentsItem->textRawJson = self::quoteRawJson($rawElement[$textFieldName]);
        }
        $statusFieldName = self::findFirstSentFieldName($rawElement, self::STATUS_FIELD_NAMES);
        if ($statusFieldName !== null) {
            $argumentsItem->hasStatusField = true;
            $argumentsItem->rawStatus = strtolower(trim(is_scalar($rawElement[$statusFieldName]) ? (string)$rawElement[$statusFieldName] : ''));
        }
        $statusNoteFieldName = self::findFirstSentFieldName($rawElement, self::STATUS_NOTE_FIELD_NAMES);
        if ($statusNoteFieldName !== null) {
            $argumentsItem->hasStatusNoteField = true;
            $argumentsItem->statusNote = trim(is_scalar($rawElement[$statusNoteFieldName]) ? (string)$rawElement[$statusNoteFieldName] : '');
        }
        $requiresUserActionFieldName = self::findFirstSentFieldName($rawElement, self::REQUIRES_USER_ACTION_FIELD_NAMES);
        if ($requiresUserActionFieldName !== null) {
            $argumentsItem->requiresUserAction = (bool)$rawElement[$requiresUserActionFieldName];
        }
        if (array_key_exists('retryable', $rawElement)) {
            $argumentsItem->retryable = (bool)$rawElement['retryable'];
        }
        if (array_key_exists('remove', $rawElement)) {
            $argumentsItem->hasRemoveField = true;
            $argumentsItem->removeIsTrue = $rawElement['remove'] === true;
            $argumentsItem->removeRawJson = self::quoteRawJson($rawElement['remove']);
        }
        $subtasksFieldName = self::findFirstSentFieldName($rawElement, self::SUBTASKS_FIELD_NAMES);
        if ($subtasksFieldName !== null) {
            $argumentsItem->hasSubtasksField = true;
            $rawSubtasks = $rawElement[$subtasksFieldName];
            $argumentsItem->subtasksRawJson = self::quoteRawJson($rawSubtasks);
            if (is_array($rawSubtasks) && $rawSubtasks !== [] && array_is_list($rawSubtasks)) {
                $argumentsItem->subtasks = UpdateTaskPlanArgumentsItems::fromRawList($rawSubtasks, $argumentsItem->requestPath);
            }
        }
        $sentFieldNames = array_merge(self::TEXT_FIELD_NAMES, self::STATUS_FIELD_NAMES, self::STATUS_NOTE_FIELD_NAMES, self::REQUIRES_USER_ACTION_FIELD_NAMES, ['retryable'], self::SUBTASKS_FIELD_NAMES);
        foreach ($sentFieldNames as $sentFieldName) {
            if (array_key_exists($sentFieldName, $rawElement)) {
                $argumentsItem->fieldNamesBesidesKeyAndRemove[] = $sentFieldName;
            }
        }
        return $argumentsItem;
    }

    /** No `key` field: the element asks for a NEW item. */
    public function isNewItem(): bool
    {
        return $this->isObject && !$this->hasKeyField;
    }

    /**
     * No `key` field, and the `task` alias carries a key ({"task": "2"}, the address shape of `set_task_status`) — the
     * element names an existing item in the wrong field, never a new item called "2" (R10's variant).
     */
    public function carriesAKeyInTheTaskField(): bool
    {
        return $this->isObject && !$this->hasKeyField && $this->textFieldName === 'task'
            && $this->text !== null && TaskPlanItem::isConformingKey($this->text);
    }

    /** The key was sent as a string that conforms to {@see TaskPlanItem::KEY_PATTERN}. */
    public function hasConformingKey(): bool
    {
        return $this->key !== null && TaskPlanItem::isConformingKey($this->key);
    }

    /** `"remove": true` was sent — the element asks to drop an item. */
    public function asksForRemoval(): bool
    {
        return $this->hasRemoveField && $this->removeIsTrue;
    }

    /** `{"key": "…", "remove": true}` with a conforming key: the element drops that item. */
    public function isRemovalOfKey(): bool
    {
        return $this->asksForRemoval() && $this->hasConformingKey();
    }

    /** The sent text is a non-empty JSON string. */
    public function hasUsableText(): bool
    {
        return $this->text !== null && $this->text !== '';
    }

    /** The sent subtasks form a non-empty JSON list. */
    public function hasUsableSubtasks(): bool
    {
        return $this->subtasks !== null;
    }

    /** A non-empty status note or a flag was sent (the field-presence rules that read them). */
    public function hasStatusNoteOrFlag(): bool
    {
        return $this->statusNote !== '' || $this->requiresUserAction !== null || $this->retryable !== null;
    }

    /** Whether `$rawValue` decodes from a JSON object — an associative array, or the empty array `{}` decodes to. */
    protected static function isJsonObject(mixed $rawValue): bool
    {
        return is_array($rawValue) && ($rawValue === [] || !array_is_list($rawValue));
    }

    /** The JSON type of a decoded value. */
    protected static function jsonTypeOf(mixed $rawValue): string
    {
        return match (true) {
            is_string($rawValue) => self::JSON_TYPE_STRING,
            is_int($rawValue), is_float($rawValue) => self::JSON_TYPE_NUMBER,
            is_bool($rawValue) => self::JSON_TYPE_BOOLEAN,
            $rawValue === null => self::JSON_TYPE_NULL,
            self::isJsonObject($rawValue) => self::JSON_TYPE_OBJECT,
            default => self::JSON_TYPE_LIST,
        };
    }

    /** How a rejection line names a non-object element: `a string`, `a number`, `a boolean`, `null`, `a list`. */
    protected static function describeNonObject(mixed $rawValue): string
    {
        return match (self::jsonTypeOf($rawValue)) {
            self::JSON_TYPE_STRING => 'a string',
            self::JSON_TYPE_NUMBER => 'a number',
            self::JSON_TYPE_BOOLEAN => 'a boolean',
            self::JSON_TYPE_NULL => 'null',
            default => 'a list',
        };
    }

    /** A decoded value as JSON, clipped to {@see MAX_QUOTED_JSON_CHARS}. */
    public static function quoteRawJson(mixed $rawValue): string
    {
        $rawJson = (string)json_encode($rawValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($rawJson) > self::MAX_QUOTED_JSON_CHARS ? mb_substr($rawJson, 0, self::MAX_QUOTED_JSON_CHARS - 1) . '…' : $rawJson;
    }

    /**
     * The first of `$fieldNames` the element carries (a sent `null` counts as sent), or null.
     *
     * @param array<string, mixed> $rawElement
     * @param string[] $fieldNames
     */
    protected static function findFirstSentFieldName(array $rawElement, array $fieldNames): ?string
    {
        foreach ($fieldNames as $fieldName) {
            if (array_key_exists($fieldName, $rawElement)) {
                return $fieldName;
            }
        }
        return null;
    }
}
