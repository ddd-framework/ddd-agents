<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * The effect a stored tool call's tool declared WHEN THE CALL WAS APPENDED: the persisted copy of
 * its {@see MCPToolEffect}, so the readers of a stored call ask the call itself instead of a process-wide catalog — and a
 * row keeps the kind it ran with, even when the tool is reclassified later. {@see self::NONE} records that the tool
 * declared no effect (the loop's `delegate`, `askOwner`) — distinct from a row without any stamp (stored before it).
 *
 * @phpstan-consistent-constructor
 */
class ToolCallEffect extends ValueObject
{
    /** The tool declared no effect when the call was appended: neither a read nor a write. */
    public const string NONE = 'NONE';

    #[Choice(choices: [MCPToolEffect::READ, MCPToolEffect::WRITE, MCPToolEffect::DELETE, self::NONE])]
    public string $effect = self::NONE;

    /** {@see MCPToolEffect::$identicalRepeatDoesNewWork} */
    public bool $identicalRepeatDoesNewWork = false;

    /** {@see MCPToolEffect::$resultIsReusedWithinTurn} */
    public bool $resultIsReusedWithinTurn = true;

    /** The stored copy of a declared effect; {@see self::NONE} when the tool declares none. */
    public static function fromMcpToolEffect(?MCPToolEffect $mcpToolEffect): static
    {
        $toolCallEffect = new static();
        if ($mcpToolEffect === null) {
            return $toolCallEffect;
        }
        $toolCallEffect->effect = $mcpToolEffect->effect;
        $toolCallEffect->identicalRepeatDoesNewWork = $mcpToolEffect->identicalRepeatDoesNewWork;
        $toolCallEffect->resultIsReusedWithinTurn = $mcpToolEffect->resultIsReusedWithinTurn;
        return $toolCallEffect;
    }

    /** The declared effect; null for {@see self::NONE}. */
    public function toMcpToolEffect(): ?MCPToolEffect
    {
        if ($this->effect === self::NONE) {
            return null;
        }
        return new MCPToolEffect($this->effect, $this->identicalRepeatDoesNewWork, $this->resultIsReusedWithinTurn);
    }
}
