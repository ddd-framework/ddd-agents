<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources;

use Attribute;

/**
 * One parameter of a data source: its name, JSON type, whether it is required. A parameter value may be
 * a literal or a `{"$state": …}` binding — a tab that changes the period re-reads the source (debounced, capped per
 * tree by `maxSourceRequestsPerTree`).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ComposedViewDataSourceParam
{
    public function __construct(
        public string $name = '',
        public string $type = 'string',
        public bool $isRequired = false,
        public string $description = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toSchemaArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'isRequired' => $this->isRequired, 'description' => $this->description];
    }
}
