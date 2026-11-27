<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionReadCacheService;

/** A provider read served through the connection-keyed read cache ({@see AgentConnectionReadCacheService}) */
class AgentConnectionCachedProviderCall
{
    public function __construct(
        protected readonly AgentConnectionReadCacheService $agentConnectionReadCacheService,
        protected readonly AgentConnection $agentConnection,
        protected readonly string $accessFingerprint,
        protected readonly AgentConnectionProviderCall $providerCall
    ) {
    }

    public function __invoke(): mixed
    {
        return $this->agentConnectionReadCacheService->remember($this->agentConnection, $this->accessFingerprint, $this->providerCall);
    }
}
