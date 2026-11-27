<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Connections;

class AgentConnectionsMessengerTransports
{
    /** @var string One verified inbound event per message (a Slack message, a button, a new mail) */
    public const string INBOUND = 'agent_connections_inbound';

    /** @var string[] */
    public const array ALL = [self::INBOUND];

    /** @var array<class-string, string> message class => transport, for the application's messenger.yaml */
    public const array ROUTING_BY_MESSAGE_CLASS = [
        AgentConnectionInboundMessage::class => self::INBOUND,
    ];
}
