<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The path of a `$from` binding into a stored tool result: a small JSONPath subset over the structured
 * shape the result stored ({@see ComposedViewBindableResultRecord}) —
 *
 * | Segment | Selects |
 * |---|---|
 * | `$` | the whole result (the path's start, required) |
 * | `.name`, `['name']`, `["name"]` | a key of an object |
 * | `[3]` | an element of a list (zero-based) |
 * | `[*]`, `.*` | every element of a list or every value of an object — the path then selects a LIST |
 *
 * `$.bookings[*]` selects the rows, `$.summary.total` one value, `$.workshops.elements[*].name` one field of every
 * row. A key that is missing on an element after a wildcard skips that element; a missing key before any wildcard
 * selects nothing. No filters, no recursion, no slices — the model copies a path, it does not program one. Reads
 * nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewToolResultPath extends ValueObject
{
    /** Characters of a path at most. */
    public const int MAX_PATH_LENGTH = 300;

    /** Segments of a path at most. */
    public const int MAX_SEGMENTS = 24;

    /** @var string The path as written. */
    public string $path = '';

    /** @var ComposedViewToolResultPathSegment[] The segments after `$`, in the order they are written. */
    public array $segments = [];

    /** The path `$path` stands for, or null when it is no path of the subset. */
    public static function fromPathString(string $path): ?static
    {
        $trimmedPath = trim($path);
        if ($trimmedPath === '' || $trimmedPath[0] !== '$' || strlen($trimmedPath) > self::MAX_PATH_LENGTH) {
            return null;
        }
        $composedViewToolResultPath = new static();
        $composedViewToolResultPath->path = $trimmedPath;
        $position = 1;
        $pathLength = strlen($trimmedPath);
        while ($position < $pathLength) {
            if (count($composedViewToolResultPath->segments) >= self::MAX_SEGMENTS) {
                return null;
            }
            $rest = substr($trimmedPath, $position);
            if (preg_match('/^\.\*/', $rest) === 1 || preg_match('/^\[\*\]/', $rest) === 1) {
                $composedViewToolResultPath->segments[] = ComposedViewToolResultPathSegment::forWildcard();
                $position += $rest[0] === '.' ? 2 : 3;
                continue;
            }
            if (preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)/', $rest, $keyMatch) === 1) {
                $composedViewToolResultPath->segments[] = ComposedViewToolResultPathSegment::forKey($keyMatch[1]);
                $position += strlen($keyMatch[0]);
                continue;
            }
            if (preg_match('/^\[(0|[1-9][0-9]{0,5})\]/', $rest, $indexMatch) === 1) {
                $composedViewToolResultPath->segments[] = ComposedViewToolResultPathSegment::forIndex((int)$indexMatch[1]);
                $position += strlen($indexMatch[0]);
                continue;
            }
            if (preg_match('/^\[(\'|")([^\'"\]]{1,120})\1\]/', $rest, $quotedKeyMatch) === 1) {
                $composedViewToolResultPath->segments[] = ComposedViewToolResultPathSegment::forKey($quotedKeyMatch[2]);
                $position += strlen($quotedKeyMatch[0]);
                continue;
            }
            return null;
        }
        return $composedViewToolResultPath;
    }

    /** Whether the path holds a wildcard — it then selects a list of every match. */
    public function selectsList(): bool
    {
        foreach ($this->segments as $pathSegment) {
            if ($pathSegment->isWildcard()) {
                return true;
            }
        }
        return false;
    }

    /**
     * What the path selects in `$resultData` (decoded JSON, objects as arrays): the one value, or — with a wildcard —
     * the list of every match. `$found` is false when a path without a wildcard reaches nothing (a missing key, an
     * index past the end); a wildcard path always finds a list, possibly empty.
     */
    public function selectFrom(mixed $resultData, bool &$found): mixed
    {
        $currentValues = [$resultData];
        $isList = false;
        foreach ($this->segments as $pathSegment) {
            $nextValues = [];
            foreach ($currentValues as $currentValue) {
                if ($pathSegment->isWildcard()) {
                    if (is_array($currentValue)) {
                        array_push($nextValues, ...array_values($currentValue));
                    }
                    continue;
                }
                if ($pathSegment->isIndex()) {
                    if (is_array($currentValue) && array_is_list($currentValue) && array_key_exists($pathSegment->index, $currentValue)) {
                        $nextValues[] = $currentValue[$pathSegment->index];
                    }
                    continue;
                }
                if (is_array($currentValue) && !array_is_list($currentValue) && array_key_exists($pathSegment->key, $currentValue)) {
                    $nextValues[] = $currentValue[$pathSegment->key];
                }
            }
            $isList = $isList || $pathSegment->isWildcard();
            $currentValues = $nextValues;
        }
        if ($isList) {
            $found = true;
            return $currentValues;
        }
        $found = $currentValues !== [];
        return $currentValues[0] ?? null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->path);
    }
}
