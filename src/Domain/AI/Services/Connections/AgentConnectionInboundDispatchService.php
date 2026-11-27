<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbounds;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\MessageHandlers\Connections\AgentConnectionInboundMessage;
use DDD\Domain\AI\Services\Connections\Adapters\InboundAgentConnectionAdapter;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;

/**
 * The fast part of inbound: verify a delivery with the provider's adapter, answer a handshake, and hand every event
 * to a worker ({@see AgentConnectionInboundMessage}). Used by the Events API route and by the Socket Mode loop, so
 * both ways behave the same. Nothing here calls a model or waits for one.
 */
class AgentConnectionInboundDispatchService
{
    /**
     * @param array<string, string> $headers lower-case header names
     * @return string|null the handshake challenge to echo, else null
     * @throws \DDD\Infrastructure\Exceptions\UnauthorizedException a delivery that fails the verification
     */
    public function acceptDelivery(string $provider, array $headers, string $rawBody): ?string
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $inboundAdapter = $agentConnectionAdaptersService->getAdapter($provider);
        if (!$inboundAdapter instanceof InboundAgentConnectionAdapter) {
            throw new NotFoundException('No inbound for this provider.');
        }
        $inbounds = $inboundAdapter->verifyAndParseDelivery($headers, $rawBody, new DateTime());
        return $this->dispatchInbounds($provider, $inbounds);
    }

    /**
     * A payload that arrived over the authenticated Socket Mode WebSocket (local and dev): the socket itself is the
     * authentication (opened with the app-level token), so there is no signature to check. Refused in production.
     *
     * @param array<string, mixed> $payload
     */
    public function acceptSocketModePayload(string $provider, array $payload): ?string
    {
        if (AgentConnectionsConfiguration::current()->isProductionStage()) {
            throw new NotFoundException('Socket Mode is not used in production.');
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $slackAdapter = $agentConnectionAdaptersService->getAdapter($provider);
        if (!$slackAdapter instanceof SlackAgentConnectionAdapter) {
            throw new NotFoundException('No Socket Mode for this provider.');
        }
        return $this->dispatchInbounds($provider, SlackAgentConnectionAdapter::parsePayload($payload));
    }

    public function dispatchInbounds(string $provider, AgentConnectionInbounds $inbounds): ?string
    {
        foreach ($inbounds as $inbound) {
            if ($inbound->kind === AgentConnectionInbound::KIND_HANDSHAKE) {
                return $inbound->handshakeChallenge;
            }
            if ($inbound->kind === AgentConnectionInbound::KIND_IGNORED) {
                continue;
            }
            $agentConnection = $this->findSystemConnectionOfInbound($provider, $inbound);
            if (!$agentConnection instanceof AgentConnection) {
                continue;
            }
            (new AgentConnectionInboundMessage((int)$agentConnection->id, (string)json_encode($inbound->toObject(false))))->dispatch();
        }
        return null;
    }

    /** The system connection of the provider whose external account (the Slack team) the event belongs to */
    protected function findSystemConnectionOfInbound(string $provider, AgentConnectionInbound $inbound): ?AgentConnection
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        $candidates = $agentConnectionsService->findOfStage(null, true, $provider)->getElements();
        foreach ($candidates as $agentConnection) {
            // an exact match of the workspace only: an event without one belongs to no connection
            if ($inbound->externalAccountId !== null && $agentConnection->externalAccountId === $inbound->externalAccountId) {
                return $agentConnection;
            }
        }
        return null;
    }
}
