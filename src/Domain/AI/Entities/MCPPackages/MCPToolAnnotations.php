<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The MCP tool annotations an external client reads on `tools/list` (MCP spec "Tool annotations"): hints that make a
 * client auto-approve a read and ask before a write. They DESCRIBE the tool, they never authorize anything — the
 * enforcement is the mount, the rights and the gates of the executor.
 *
 * Derived from the tool's own declarations, never from its name: {@see MCPToolEffect} and
 * {@see MCPToolReturnsUntrustedContent}. All four hints are always set, because the spec's defaults
 * (`destructiveHint: true`, `openWorldHint: true`) would otherwise mark every unannotated read as destructive.
 *
 * @phpstan-consistent-constructor
 */
class MCPToolAnnotations extends ValueObject
{
    /** True when the tool changes nothing outside the conversation. */
    public bool $readOnlyHint = false;

    /** True when a write removes something or acts outward (publish, send, import, a mandate). */
    public bool $destructiveHint = false;

    /** True when a byte-identical repeat has no further effect. */
    public bool $idempotentHint = false;

    /** True when the tool reaches outside our own records: an outward action, or a result that carries third-party content. */
    public bool $openWorldHint = false;

    /**
     * The hints of a tool from its declarations. Null without an effect — every tool declares one (an application's
     * own test may check that), so a null here is a defect such a test catches, not a state to render.
     */
    public static function fromToolDeclarations(?MCPToolEffect $effect, bool $returnsUntrustedContent): ?static
    {
        if ($effect === null) {
            return null;
        }
        $annotations = new static();
        $annotations->readOnlyHint = $effect->isReadOnly();
        $annotations->destructiveHint = $effect->effect === MCPToolEffect::DELETE || $effect->isOutwardAction;
        $annotations->idempotentHint = $effect->isReadOnly() || !$effect->identicalRepeatDoesNewWork;
        $annotations->openWorldHint = $effect->isOutwardAction || $returnsUntrustedContent;
        return $annotations;
    }
}
