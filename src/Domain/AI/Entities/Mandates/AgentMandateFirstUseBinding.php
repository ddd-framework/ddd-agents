<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A one-instruction mandate that a tool call bound on its first use, with the key it was bound under. The tool
 * executor remembers it for the running call: a call that fails did no outward work, so the binding is handed back
 * ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::releaseOneInstructionMandateBinding()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentMandateFirstUseBinding extends ValueObject
{
    /** @var AgentMandate|null The mandate the call bound */
    public ?AgentMandate $agentMandate = null;

    /** @var string The key the mandate was bound under */
    public string $bindingKey = '';

    /**
     * @param AgentMandate $agentMandate
     * @param string $bindingKey
     * @return static
     */
    public static function forMandateAndBindingKey(AgentMandate $agentMandate, string $bindingKey): static
    {
        $firstUseBinding = new static();
        $firstUseBinding->agentMandate = $agentMandate;
        $firstUseBinding->bindingKey = $bindingKey;
        return $firstUseBinding;
    }
}
