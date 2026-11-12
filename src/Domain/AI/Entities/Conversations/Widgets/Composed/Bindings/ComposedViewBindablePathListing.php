<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\SerializeInToonFormat;

/**
 * The `$from` paths one stored tool result offers (tools that may be bound show their bindable paths,
 * generated from the DTO), read off the structured shape the result stored ({@see ComposedViewBindableResultRecord})
 * — the result object, not its markdown — and rendered as the one line the model reads under the result:
 *
 * `[ComposedView $from paths of toolCall "call_7": $.bookings.elements[*] (5 rows: id, name, date, …); $.maxAllowed]`
 *
 * A list of objects is a row list (`…[*]`, its row count and the keys of its first row — its scalar and list keys
 * first, then the leaves of its object keys as dotted keys, `author.name`, which a `{"$item":"author.name"}` reads); a
 * list of plain values is a value list; a scalar key is a value. The walk stops at {@see MAX_DEPTH} keys deep and inside a row list (its rows are
 * addressed through the list), and lists at most {@see MAX_LISTED_PATHS} paths. A TOON string (a key ending in
 * {@see SerializeInToonFormat::TOON_PROPERTY_POSTFIX}, the serializer's text form of a list) is no path: a binding
 * cannot read rows out of it. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewBindablePathListing extends ValueObject
{
    /** Keys deep the walk descends at most below `$`. */
    public const int MAX_DEPTH = 3;

    /** Paths the line names at most. */
    public const int MAX_LISTED_PATHS = 10;

    /** Keys of a row the line names at most. */
    public const int MAX_LISTED_ROW_KEYS = 12;

    /** Levels of nested objects below a row key whose leaves the line names as dotted keys (`changeHistory.createdTime`). */
    public const int MAX_ROW_LEAF_DEPTH = 2;

    /** Keys the serializer adds that address nothing a widget shows. */
    public const array IGNORED_KEYS = ['objectType'];

    /** @var ComposedViewBindablePath[] The paths, in walk order. */
    public array $listedPaths = [];

    /** @var bool More paths exist than {@see MAX_LISTED_PATHS}. */
    public bool $hasMorePaths = false;

    /** The paths of a decoded result shape (objects as arrays). */
    public static function fromResultData(mixed $resultData): static
    {
        $composedViewBindablePathListing = new static();
        if (is_array($resultData) && array_is_list($resultData)) {
            $composedViewBindablePathListing->addListPath('$', $resultData);
        } elseif (is_array($resultData)) {
            $composedViewBindablePathListing->collectObjectPaths('$', $resultData, 1);
        }
        return $composedViewBindablePathListing;
    }

    /** The line the model reads under the result; null when the shape offers no path. */
    public function toModelLine(string $toolCallId): ?string
    {
        if ($this->listedPaths === [] || $toolCallId === '') {
            return null;
        }
        $pathTexts = [];
        foreach ($this->listedPaths as $listedPath) {
            $pathTexts[] = $listedPath->toModelText();
        }
        return '[ComposedView $from paths of toolCall "' . $toolCallId . '": ' . implode('; ', $pathTexts) . ($this->hasMorePaths ? '; …' : '') . ']';
    }

    /** @param array<mixed> $objectValue an object (string keys) */
    protected function collectObjectPaths(string $path, array $objectValue, int $depth): void
    {
        foreach ($objectValue as $key => $value) {
            if (in_array($key, self::IGNORED_KEYS, true) || $value === null || $value === [] || self::isToonString((string)$key, $value)) {
                continue;
            }
            $keyPath = $path . (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', (string)$key) === 1 ? '.' . $key : "['" . $key . "']");
            if (!is_array($value)) {
                $this->addPath($keyPath, null, []);
                continue;
            }
            if (array_is_list($value)) {
                $this->addListPath($keyPath, $value);
                continue;
            }
            if ($depth < self::MAX_DEPTH) {
                $this->collectObjectPaths($keyPath, $value, $depth + 1);
            }
        }
    }

    /** Whether a key holds a TOON string — the serializer's text form of a list, which no binding can address. */
    public static function isToonString(string $key, mixed $value): bool
    {
        return is_string($value) && str_ends_with($key, SerializeInToonFormat::TOON_PROPERTY_POSTFIX);
    }

    /** @param array<int, mixed> $listValue */
    protected function addListPath(string $path, array $listValue): void
    {
        $firstElement = $listValue[0] ?? null;
        if (is_array($firstElement) && !array_is_list($firstElement)) {
            $this->addPath($path . '[*]', count($listValue), self::buildListedRowKeys($firstElement));
            return;
        }
        $this->addPath($path, count($listValue), []);
    }

    /**
     * The keys of one row as the line names them: the scalar and list keys first, then the leaves of its object keys as
     * dotted keys, at most {@see MAX_LISTED_ROW_KEYS}, `…` when more exist. Also the field list of an `$item` finding
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading}).
     *
     * @param array<mixed> $row an object row
     * @return string[]
     */
    public static function buildListedRowKeys(array $row): array
    {
        $rowKeys = self::collectRowKeys($row, '', 0);
        $listedRowKeys = array_slice($rowKeys, 0, self::MAX_LISTED_ROW_KEYS);
        if (count($rowKeys) > count($listedRowKeys)) {
            $listedRowKeys[] = '…';
        }
        return $listedRowKeys;
    }

    /**
     * Every key of a row: the scalar and list keys of this level, then the dotted leaves of its object keys.
     *
     * @param array<mixed> $row
     * @return string[]
     */
    protected static function collectRowKeys(array $row, string $keyPrefix, int $leafDepth): array
    {
        $ownKeys = [];
        $leafKeys = [];
        foreach ($row as $rowKey => $rowValue) {
            if (in_array($rowKey, self::IGNORED_KEYS, true)) {
                continue;
            }
            $dottedKey = $keyPrefix . $rowKey;
            if (is_array($rowValue) && $rowValue !== [] && !array_is_list($rowValue) && $leafDepth < self::MAX_ROW_LEAF_DEPTH) {
                array_push($leafKeys, ...self::collectRowKeys($rowValue, $dottedKey . '.', $leafDepth + 1));
                continue;
            }
            $ownKeys[] = $dottedKey;
        }
        return [...$ownKeys, ...$leafKeys];
    }

    /** @param string[] $rowKeys */
    protected function addPath(string $path, ?int $rowCount, array $rowKeys): void
    {
        if (count($this->listedPaths) >= self::MAX_LISTED_PATHS) {
            $this->hasMorePaths = true;
            return;
        }
        $this->listedPaths[] = ComposedViewBindablePath::forPath($path, $rowCount, $rowKeys);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
