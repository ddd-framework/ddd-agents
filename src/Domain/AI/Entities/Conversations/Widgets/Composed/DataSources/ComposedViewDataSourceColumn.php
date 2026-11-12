<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources;

use Attribute;

/** One column of a data source's rows — what a `Table` column or a `Chart` series may bind. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ComposedViewDataSourceColumn
{
    public function __construct(
        public string $name = '',
        public string $type = 'string',
        public string $description = '',
    ) {
    }

    /** @return array<string, string> */
    public function toSchemaArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'description' => $this->description];
    }
}
