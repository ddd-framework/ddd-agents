<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property MCPPackage[] $elements
 * @method MCPPackage|null first()
 * @method MCPPackage|null getByUniqueKey(string $uniqueKey)
 * @method MCPPackage[] getElements()
 */
class MCPPackages extends ObjectSet
{
    /** @var string|null A unique name of this MCP Package */
    public ?string $name;

    /** @var string|null Description of the MCP Package */
    public ?string $description;
}
