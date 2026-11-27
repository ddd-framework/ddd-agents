<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Connections;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * One verified inbound event of a connection, handed from the route (which must answer the provider within seconds)
 * or the Socket Mode loop to a worker. Carries the parsed event as JSON, never a secret.
 */
class AgentConnectionInboundMessage extends AppMessage
{
    public const string TRANSPORT = AgentConnectionsMessengerTransports::INBOUND;

    public static string $messageHandler = AgentConnectionInboundHandler::class;

    public int $agentConnectionId;

    /** @var string the JSON of {@see \DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound} */
    public string $inboundJson;

    public function __construct(int $agentConnectionId, string $inboundJson)
    {
        parent::__construct();
        $this->agentConnectionId = $agentConnectionId;
        $this->inboundJson = $inboundJson;
    }
}
