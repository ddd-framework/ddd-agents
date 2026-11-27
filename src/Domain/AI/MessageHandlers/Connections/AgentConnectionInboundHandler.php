<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\MessageHandlers\AIDomainMessageHandler;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundService;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(fromTransport: AgentConnectionInboundMessage::TRANSPORT)]
class AgentConnectionInboundHandler extends AIDomainMessageHandler
{
    public function __invoke(AgentConnectionInboundMessage $message): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentConnectionsService $agentConnectionsService */
            $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
            $agentConnection = $agentConnectionsService->findIgnoringRights($message->agentConnectionId);
            if (!$agentConnection instanceof AgentConnection) {
                return;
            }
            $inbound = AgentConnectionInbound::newInstance();
            $inboundValues = json_decode($message->inboundJson);
            if (is_object($inboundValues)) {
                $inbound->setPropertiesFromObject($inboundValues, false);
            }
            /** @var AgentConnectionInboundService $agentConnectionInboundService */
            $agentConnectionInboundService = DDDService::instance()->getService(AgentConnectionInboundService::class);
            $agentConnectionInboundService->handle($agentConnection, $inbound);
        } catch (Throwable $inboundFailure) {
            // one event never blocks the queue: logged and dropped (the provider does not resend a handled delivery)
            $this->logShortException($this->getLogger(), "Inbound event of connection {$message->agentConnectionId}", $inboundFailure);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
