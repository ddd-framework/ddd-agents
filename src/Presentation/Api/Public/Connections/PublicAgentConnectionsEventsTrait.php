<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Public\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundDispatchService;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Post;
use Symfony\Component\HttpFoundation\Request;

/**
 * The inbound endpoint of the chat providers (Events API and interactivity of Slack; one URL for both). Verifies the
 * signature, answers a handshake, dispatches every event to a worker and answers at once (Slack waits 3 s). Public
 * in the application's firewall: the signature is the authentication.
 *
 *     #[Route('/agent-connections')]
 *     class PublicAgentConnectionsEventsController extends HttpController { use PublicAgentConnectionsEventsTrait; }
 */
trait PublicAgentConnectionsEventsTrait
{
    /** Slack Events API and interactivity deliveries; the signature is the authentication. */
    #[Post('/slack/events')]
    #[Summary('AgentConnections Slack Events')]
    public function receiveSlackEvents(Request $request): RestResponseDto
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if (!$agentConnectionsConfiguration->isAdapterEnabled(AgentConnection::PROVIDER_SLACK)) {
            throw new ForbiddenException('feature_not_available');
        }
        $headers = [];
        foreach ($request->headers->all() as $headerName => $headerValues) {
            $headers[strtolower((string)$headerName)] = (string)($headerValues[0] ?? '');
        }
        /** @var AgentConnectionInboundDispatchService $agentConnectionInboundDispatchService */
        $agentConnectionInboundDispatchService = DDDService::instance()->getService(AgentConnectionInboundDispatchService::class);
        $challenge = $agentConnectionInboundDispatchService->acceptDelivery(AgentConnection::PROVIDER_SLACK, $headers, (string)$request->getContent());
        return new RestResponseDto($challenge !== null ? ['challenge' => $challenge] : ['ok' => true]);
    }
}
