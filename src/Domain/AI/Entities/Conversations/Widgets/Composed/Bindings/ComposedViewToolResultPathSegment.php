<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One segment of a `$from` path after its `$` ({@see ComposedViewToolResultPath::$segments}): a key of an object, an
 * element of a list, or every element of a list / every value of an object.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewToolResultPathSegment extends ValueObject
{
    public const string KIND_KEY = 'key';
    public const string KIND_INDEX = 'index';
    public const string KIND_WILDCARD = 'wildcard';

    /** @var string What the segment selects, one of the `KIND_*` constants */
    public string $kind = self::KIND_KEY;

    /** @var string|null The object key a `KIND_KEY` segment selects; null for the other kinds */
    public ?string $key = null;

    /** @var int|null The zero-based list position a `KIND_INDEX` segment selects; null for the other kinds */
    public ?int $index = null;

    /**
     * @param string $key
     * @return static The segment selecting the key `$key` of an object
     */
    public static function forKey(string $key): static
    {
        $pathSegment = new static();
        $pathSegment->kind = self::KIND_KEY;
        $pathSegment->key = $key;
        return $pathSegment;
    }

    /**
     * @param int $index
     * @return static The segment selecting the element at `$index` of a list
     */
    public static function forIndex(int $index): static
    {
        $pathSegment = new static();
        $pathSegment->kind = self::KIND_INDEX;
        $pathSegment->index = $index;
        return $pathSegment;
    }

    /** @return static The segment selecting every element of a list or every value of an object */
    public static function forWildcard(): static
    {
        $pathSegment = new static();
        $pathSegment->kind = self::KIND_WILDCARD;
        return $pathSegment;
    }

    public function isWildcard(): bool
    {
        return $this->kind === self::KIND_WILDCARD;
    }

    public function isIndex(): bool
    {
        return $this->kind === self::KIND_INDEX;
    }
}
