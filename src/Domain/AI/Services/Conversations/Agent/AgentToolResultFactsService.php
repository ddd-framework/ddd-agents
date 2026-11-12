<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\Services\Service;

/**
 * The facts the platform keeps from a tool result beside the markdown the model reads (persisted on the tool response,
 * stripped from the wire) — read later by an application's own records (e.g. a diary about the space). The default
 * keeps none. An application puts a same-path subclass under its own root namespace; the core resolves this service only
 * through `DDDService::instance()->getService(AgentToolResultFactsService::class)`.
 */
class AgentToolResultFactsService extends Service
{
    /**
     * The facts of one serialized tool result, keyed by fact path, in walk order. The default: none.
     *
     * @param mixed $serializedResult the result as the executor serialized it for the model (nested objects, arrays, scalars)
     * @return array<string, int|float|string> fact path → value
     */
    public function extractPreservedFacts(mixed $serializedResult): array
    {
        return [];
    }
}
