<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks a property of a value object / DTO as SERVER-FILLED: it is part of what a tool RETURNS, never of what the
 * model may send. The tool's INPUT JSON-Schema omits it ({@see MCPSchema::omitsOutputOnlyProperty()}); the OUTPUT
 * schema keeps it, and hydration/serialization are untouched — this is a documentation-size mechanism, not a
 * security gate (a client that sends the property anyway simply has it overwritten by the resolution).
 *
 * Why it exists: a value object that travels BOTH ways — the model may pass it, the tool resolves it and echoes it
 * back enriched — otherwise pays for its resolved half in EVERY turn the tool is loaded, for fields the model must
 * never fill. Example: a selection the owner marks in an editor of the application — the model passes the marked
 * element, and the coordinates of the selection are resolved server-side from that element's facts.
 *
 * Distinct from {@see \DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty} (drops the property from BOTH
 * schemas AND from the serialized bytes) and from {@see MCPServerProvidedInput} (a whole PARAMETER the executor
 * injects).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MCPOutputOnlyProperty
{
}
