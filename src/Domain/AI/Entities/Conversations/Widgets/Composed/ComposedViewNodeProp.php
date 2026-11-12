<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use Attribute;

/**
 * One prop of a `ComposedView` node type, declared on the node class: its name, the JSON types it takes,
 * whether it is required, whether it may be a binding (`{"$state":…}`, `{"$computed":…}`, `{"$item":…}`, `{"$from":…}`,
 * `{"$source":…}`) instead of a literal, its allowed values and a one-line description for the model spec. A prop the
 * class does not declare is dropped and reported (`INVALID_PROP`); an event prop (`onChange`, `onClick`) takes
 * `{"set": "<state name>"}` or `{"action": "<action name>"}`. Exported into `composed-node-schema.json`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ComposedViewNodeProp
{
    public const string TYPE_STRING = 'string';
    public const string TYPE_NUMBER = 'number';
    public const string TYPE_BOOLEAN = 'boolean';
    public const string TYPE_ARRAY = 'array';
    public const string TYPE_OBJECT = 'object';
    /** an event: `{"set": "<state name>"}` or `{"action": "<action name>"}` */
    public const string TYPE_EVENT = 'event';

    /**
     * @param string[] $types the `TYPE_*` values the prop takes
     * @param array<int, string|int|float|bool>|null $allowedValues a closed list (an enum), null = free
     */
    public function __construct(
        public string $name = '',
        public array $types = [self::TYPE_STRING],
        public bool $isRequired = false,
        public bool $isBindable = true,
        public ?array $allowedValues = null,
        public string $description = '',
    ) {
    }

    /** Whether the literal `$propValue` (no binding) has one of the declared types and, for an enum, an allowed value. */
    public function acceptsLiteral(mixed $propValue): bool
    {
        if ($this->allowedValues !== null && !in_array($propValue, $this->allowedValues, true)) {
            return false;
        }
        foreach ($this->types as $type) {
            $fits = match ($type) {
                self::TYPE_STRING => is_string($propValue),
                self::TYPE_NUMBER => is_int($propValue) || is_float($propValue),
                self::TYPE_BOOLEAN => is_bool($propValue),
                self::TYPE_ARRAY => is_array($propValue) && array_is_list($propValue),
                self::TYPE_OBJECT => is_array($propValue) && ($propValue === [] || !array_is_list($propValue)),
                default => false,
            };
            if ($fits) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> the prop as `composed-node-schema.json` lists it */
    public function toSchemaArray(): array
    {
        return [
            'name' => $this->name,
            'types' => array_values($this->types),
            'isRequired' => $this->isRequired,
            'isBindable' => $this->isBindable,
            'allowedValues' => $this->allowedValues,
            'description' => $this->description,
        ];
    }
}
