<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPSchema;

/** An input schema whose serialized form is the array the test hands in. */
class ProbeMCPSchemaWithFixedWireArray extends MCPSchema
{
    /** @var array<string, mixed> */
    public array $fixedWireArray = [];

    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        return $this->fixedWireArray;
    }
}
