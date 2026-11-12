<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A bound value in a `ComposedView` tree: an object with exactly one `$`-key, never a string
 * template, so nothing parses strings at render time.
 *
 * | Wire | Kind | Meaning |
 * |---|---|---|
 * | `{"$state":"period"}` | state | the current value of a state name |
 * | `{"$computed":"plotted"}` | computed | the value of a computed expression |
 * | `{"$data":"rows"}` | data | a named dataset of the tree (`data`: inline rows with sources, `$from`, `$source`) |
 * | `{"$item":"x"}` | item | a field of the current row inside a node that provides an item scope (`Repeat`); a dotted `a.b` reads a field of a nested object ({@see readRowField()}) |
 * | `{"$from":{"toolCall":"call_7","path":"$.bookings[*]"}}` | from | a tool result of this conversation, resolved and snapshotted when the row is stored |
 * | `{"$source":{"name":"bookings","params":{…}}}` | source | a registered data source the frontend hydrates from |
 *
 * Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewBinding extends ValueObject
{
    public const string KIND_STATE = 'state';
    public const string KIND_COMPUTED = 'computed';
    public const string KIND_DATA = 'data';
    public const string KIND_ITEM = 'item';
    public const string KIND_FROM = 'from';
    public const string KIND_SOURCE = 'source';

    /** @var string[] the binding kinds, as the wire writes them without the `$` */
    public const array KINDS = [self::KIND_STATE, self::KIND_COMPUTED, self::KIND_DATA, self::KIND_ITEM, self::KIND_FROM, self::KIND_SOURCE];

    /** @var string One of the KIND_* constants. */
    public string $kind = '';

    /** @var string The state / computed / data / item name, the tool call id (`from`) or the source name (`source`). */
    public string $target = '';

    /** @var string|null The JSONPath into the tool result (`from` only). */
    public ?string $path = null;

    /** @var array<string, mixed> The source parameters (`source` only). */
    public array $sourceParams = [];

    /** @var bool Whether the wire value names a binding at all (one `$`-key) — a malformed binding is still a binding. */
    public bool $isWellFormed = true;

    /**
     * The binding a wire value stands for, or null when the value is a literal (anything but an object whose only key
     * starts with `$`). A `$`-object with an unknown kind or a bad shape is returned with `isWellFormed = false`.
     */
    public static function fromWireValue(mixed $wireValue): ?static
    {
        if (!is_array($wireValue) || count($wireValue) !== 1 || array_is_list($wireValue)) {
            return null;
        }
        $bindingKey = (string)array_key_first($wireValue);
        if (!str_starts_with($bindingKey, '$')) {
            return null;
        }
        $composedViewBinding = new static();
        $composedViewBinding->kind = substr($bindingKey, 1);
        $bindingValue = $wireValue[$bindingKey];
        if (!in_array($composedViewBinding->kind, self::KINDS, true)) {
            $composedViewBinding->isWellFormed = false;
            return $composedViewBinding;
        }
        if (in_array($composedViewBinding->kind, [self::KIND_STATE, self::KIND_COMPUTED, self::KIND_DATA, self::KIND_ITEM], true)) {
            $composedViewBinding->isWellFormed = is_string($bindingValue) && $bindingValue !== '';
            $composedViewBinding->target = is_string($bindingValue) ? $bindingValue : '';
            return $composedViewBinding;
        }
        if ($composedViewBinding->kind === self::KIND_FROM) {
            $composedViewBinding->target = is_array($bindingValue) && is_string($bindingValue['toolCall'] ?? null) ? $bindingValue['toolCall'] : '';
            $composedViewBinding->path = is_array($bindingValue) && is_string($bindingValue['path'] ?? null) ? $bindingValue['path'] : null;
            $composedViewBinding->isWellFormed = $composedViewBinding->target !== '' && $composedViewBinding->path !== null && $composedViewBinding->path !== '';
            return $composedViewBinding;
        }
        $composedViewBinding->target = is_array($bindingValue) && is_string($bindingValue['name'] ?? null) ? $bindingValue['name'] : '';
        $sourceParams = is_array($bindingValue) ? ($bindingValue['params'] ?? []) : null;
        $composedViewBinding->sourceParams = is_array($sourceParams) ? $sourceParams : [];
        $composedViewBinding->isWellFormed = $composedViewBinding->target !== '' && is_array($sourceParams);
        return $composedViewBinding;
    }

    /**
     * The field an `$item` binding names in one row: the key as written, else — for a dotted name (`author.name`) — the
     * path through the row's nested objects. `$found` tells a field that holds null from a missing one. The frontend
     * twin is `readComposedViewRowField()` (`composed-view-tree.ts`).
     *
     * @param array<mixed> $row
     */
    public static function readRowField(array $row, string $fieldName, bool &$found): mixed
    {
        $found = array_key_exists($fieldName, $row);
        if ($found || !str_contains($fieldName, '.')) {
            return $found ? $row[$fieldName] : null;
        }
        $fieldValue = $row;
        foreach (explode('.', $fieldName) as $fieldNamePart) {
            if (!is_array($fieldValue) || array_is_list($fieldValue) || !array_key_exists($fieldNamePart, $fieldValue)) {
                return null;
            }
            $fieldValue = $fieldValue[$fieldNamePart];
        }
        $found = true;
        return $fieldValue;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->kind . ':' . $this->target . ':' . (string)$this->path);
    }
}
