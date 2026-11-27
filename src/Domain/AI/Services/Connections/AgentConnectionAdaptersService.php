<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;

/**
 * Resolves the adapter of a provider (`AI.agentConnections.adapters.classes`). A switched-off adapter is refused: its
 * connections neither read nor send while it is off.
 */
class AgentConnectionAdaptersService
{
    /** @var array<string, AgentConnectionAdapter> */
    protected array $adaptersByProvider = [];

    /** @throws AgentConnectionRefusedException */
    public function getAdapter(string $provider): AgentConnectionAdapter
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if (!$agentConnectionsConfiguration->isAdapterEnabled($provider)) {
            throw new AgentConnectionRefusedException("The connection provider $provider is switched off in this installation.");
        }
        if (isset($this->adaptersByProvider[$provider])) {
            return $this->adaptersByProvider[$provider];
        }
        $adapterClass = $agentConnectionsConfiguration->getAdapterClasses()[$provider] ?? null;
        if (!is_string($adapterClass) || !is_a($adapterClass, AgentConnectionAdapter::class, true)) {
            throw new AgentConnectionRefusedException("No adapter is registered for the provider $provider.");
        }
        return $this->adaptersByProvider[$provider] = new $adapterClass();
    }

    /** @throws AgentConnectionRefusedException */
    public function getAdapterOfConnection(AgentConnection $agentConnection): AgentConnectionAdapter
    {
        return $this->getAdapter((string)$agentConnection->provider)->forConnection($agentConnection);
    }

    /** A test registers its double here */
    public function registerAdapter(string $provider, AgentConnectionAdapter $agentConnectionAdapter): void
    {
        $this->adaptersByProvider[$provider] = $agentConnectionAdapter;
    }

    public function forgetRegisteredAdapters(): void
    {
        $this->adaptersByProvider = [];
    }
}
