<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings of one adapter (`AI.agentConnections.adapters.<PROVIDER>`). Secrets are never here: only the names of
 * the environment variables that hold them.
 */
class AgentConnectionAdapterSettings extends ValueObject
{
    public bool $enabled = false;
    /** @var string OAuth client id and secret (Google) */
    public string $clientIdEnvironmentVariable = '';
    public string $clientSecretEnvironmentVariable = '';
    /** @var string[] Workspace domains whose accounts may connect (Google `hd`); empty = any */
    public array $allowedHostedDomains = [];
    /** @var string Chat bot token, request signing secret, app-level token (Slack) */
    public string $botTokenEnvironmentVariable = '';
    public string $signingSecretEnvironmentVariable = '';
    public string $appTokenEnvironmentVariable = '';
    /** @var string EVENTS_API or SOCKET_MODE */
    public string $inboundMode = 'EVENTS_API';
    /** @var string The one fixed line an unlinked chat user gets (no model call) */
    public string $unknownUserLine = 'I only answer linked team members.';
    /** @var bool Whether the bot answers in channels the connection does not name (default: no) */
    public bool $answerInUnnamedChannels = false;
    /** @var string[] The authserv-ids of the mailbox's own receiving servers (IMAP): only their Authentication-Results prove a sender; empty = no sender is proven */
    public array $trustedAuthenticationServerIds = [];

    /** @param array<string, mixed> $configuredSettings */
    public static function fromConfiguration(array $configuredSettings): static
    {
        $adapterSettings = static::newInstance();
        $adapterSettings->enabled = (bool)($configuredSettings['enabled'] ?? false);
        $adapterSettings->clientIdEnvironmentVariable = (string)($configuredSettings['clientIdEnvironmentVariable'] ?? '');
        $adapterSettings->clientSecretEnvironmentVariable = (string)($configuredSettings['clientSecretEnvironmentVariable'] ?? '');
        $adapterSettings->allowedHostedDomains = array_values(array_map('strval', (array)($configuredSettings['allowedHostedDomains'] ?? [])));
        $adapterSettings->botTokenEnvironmentVariable = (string)($configuredSettings['botTokenEnvironmentVariable'] ?? '');
        $adapterSettings->signingSecretEnvironmentVariable = (string)($configuredSettings['signingSecretEnvironmentVariable'] ?? '');
        $adapterSettings->appTokenEnvironmentVariable = (string)($configuredSettings['appTokenEnvironmentVariable'] ?? '');
        $adapterSettings->inboundMode = (string)($configuredSettings['inboundMode'] ?? 'EVENTS_API');
        if (isset($configuredSettings['unknownUserLine'])) {
            $adapterSettings->unknownUserLine = (string)$configuredSettings['unknownUserLine'];
        }
        $adapterSettings->answerInUnnamedChannels = (bool)($configuredSettings['answerInUnnamedChannels'] ?? false);
        $adapterSettings->trustedAuthenticationServerIds = array_values(array_map('strval', (array)($configuredSettings['trustedAuthenticationServerIds'] ?? [])));
        return $adapterSettings;
    }
}
