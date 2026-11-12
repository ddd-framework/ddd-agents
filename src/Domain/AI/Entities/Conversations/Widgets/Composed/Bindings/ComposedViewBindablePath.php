<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One `$from` path a stored tool result offers ({@see ComposedViewBindablePathListing::$listedPaths}): the path, and
 * for a list the number of its elements and — for a list of objects — the keys of its first row.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewBindablePath extends ValueObject
{
    /** @var string The path as a binding writes it (`$.competitors.elements[*]`, `$.maxAllowed`) */
    public string $path = '';

    /** @var int|null The number of elements when the path selects a list; null when it selects one value */
    public ?int $rowCount = null;

    /** @var string[] The keys of the list's first row when the list holds objects, `…` last when more exist; empty otherwise */
    public array $rowKeys = [];

    /**
     * @param string $path
     * @param int|null $rowCount
     * @param string[] $rowKeys
     * @return static
     */
    public static function forPath(string $path, ?int $rowCount, array $rowKeys): static
    {
        $composedViewBindablePath = new static();
        $composedViewBindablePath->path = $path;
        $composedViewBindablePath->rowCount = $rowCount;
        $composedViewBindablePath->rowKeys = $rowKeys;
        return $composedViewBindablePath;
    }

    /** @return string The path as the model reads it: `$.maxAllowed`, `$.names (2 rows)`, `$.rows[*] (5 rows: id, name)` */
    public function toModelText(): string
    {
        if ($this->rowCount === null) {
            return $this->path;
        }
        return $this->path . ' (' . $this->rowCount . ($this->rowCount === 1 ? ' row' : ' rows')
            . ($this->rowKeys !== [] ? ': ' . implode(', ', $this->rowKeys) : '') . ')';
    }
}
