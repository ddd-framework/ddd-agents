<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks a request property of a tool whose EMPTY STRING is a value of its own (it removes the text the property
 * holds). Everywhere else the executor reads an empty string on an optional parameter as "not passed": a model that
 * fills every parameter of a schema sends `""` as a placeholder, and a placeholder must neither fail a validation
 * nor overwrite what is stored.
 *
 *   #[MCPEmptyStringIsValue]
 *   public ?string $doneCriteria = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MCPEmptyStringIsValue
{
}
