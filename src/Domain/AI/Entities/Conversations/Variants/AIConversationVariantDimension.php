<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Variants;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One DIMENSION of a purposed conversation's situation (skill variants): a named lane
 * with a closed set of values, declared by the purpose ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::getVariantDimensions()}).
 * The skills carry sections keyed `[name=value]`; the resolved value selects which sections the model sees.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationVariantDimension extends ValueObject
{
    public const string VALUE_UNKNOWN = 'unknown';

    /** @var string The dimension name as the skill markers spell it, e.g. `businessClass` */
    public string $name = '';

    /** @var string[] The allowed values, including the default */
    public array $values = [];

    /** @var string The value while nothing in the record decides the dimension */
    public string $default = self::VALUE_UNKNOWN;

    /**
     * @var bool Whether only an owner statement DECIDES the dimension: a record default (a value an import
     *      wrote) is then a proposal — its section renders, but guards keep treating the value as open
     */
    public bool $decidedByOwnerOnly = false;

    /** @param string[] $values */
    public static function of(string $name, array $values, string $default = self::VALUE_UNKNOWN, bool $decidedByOwnerOnly = false): static
    {
        $dimension = new static();
        $dimension->name = $name;
        $dimension->values = array_values(array_unique(array_merge($values, [$default])));
        $dimension->default = $default;
        $dimension->decidedByOwnerOnly = $decidedByOwnerOnly;
        return $dimension;
    }

    public function allowsValue(string $value): bool
    {
        return in_array($value, $this->values, true);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
