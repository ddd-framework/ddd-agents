<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

/**
 * The rights of AgentConnections. The application maps them to its accounts (its AgentAccountRights mapping):
 * - USE_AGENT_CONNECTIONS: the agent's connection tools (`Agent.Connections.*`) in this account's conversations;
 * - MANAGE_OWN_AGENT_CONNECTIONS: connect, configure and share the account's own connections;
 * - MANAGE_SYSTEM_AGENT_CONNECTIONS: the agent's own identities (chat bot, team mailboxes), their channels and links.
 */
class AgentConnectionsAccountRights
{
    public const string USE_AGENT_CONNECTIONS = 'useAgentConnections';
    public const string MANAGE_OWN_AGENT_CONNECTIONS = 'manageOwnAgentConnections';
    public const string MANAGE_SYSTEM_AGENT_CONNECTIONS = 'manageSystemAgentConnections';

    /** @var string[] */
    public const array RIGHTS = [self::USE_AGENT_CONNECTIONS, self::MANAGE_OWN_AGENT_CONNECTIONS, self::MANAGE_SYSTEM_AGENT_CONNECTIONS];
}
