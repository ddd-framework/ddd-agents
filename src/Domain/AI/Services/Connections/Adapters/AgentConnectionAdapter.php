<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAdapterSettings;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\AgentConnectionCredentialSetsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionSecretPayload;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use LogicException;

/**
 * The only thing a new provider implements: one adapter class per provider, registered in
 * `AI.agentConnections.adapters.classes`, plus the capability interfaces it offers ({@see MailAgentConnectionAdapter},
 * {@see CalendarAgentConnectionAdapter}, {@see ContactsAgentConnectionAdapter}, {@see FilesAgentConnectionAdapter},
 * {@see ChatAgentConnectionAdapter}), the connect flow ({@see OAuthAgentConnectionAdapter} or credentials of its own)
 * and inbound ({@see InboundAgentConnectionAdapter}).
 *
 * An adapter is bound to ONE connection per call ({@see forConnection()}); the guards, the approval and the audit run
 * in the core before it is called ({@see \DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService}), so an
 * adapter only talks to its provider. Secrets are read inside the call and never leave it.
 */
abstract class AgentConnectionAdapter
{
    public const string PROVIDER = '';

    protected ?AgentConnection $agentConnection = null;

    /** @return string[] the capabilities ({@see \DDD\Domain\AI\Entities\Connections\AgentConnectionPermission}) it offers */
    abstract public function getCapabilities(): array;

    /** A copy bound to `$agentConnection` */
    public function forConnection(AgentConnection $agentConnection): static
    {
        $boundAdapter = clone $this;
        $boundAdapter->agentConnection = $agentConnection;
        return $boundAdapter;
    }

    public function getProvider(): string
    {
        return static::PROVIDER;
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /** @return array<string, mixed> the adapter's entry of `AI.agentConnections.adapters` */
    protected function getSettings(): AgentConnectionAdapterSettings
    {
        return AgentConnectionsConfiguration::current()->getAdapterSettings(static::PROVIDER);
    }

    protected function requireConnection(): AgentConnection
    {
        if (!$this->agentConnection instanceof AgentConnection) {
            throw new LogicException(static::class . ' is used without a connection; call forConnection() first.');
        }
        return $this->agentConnection;
    }

    protected function readSecrets(): AgentConnectionSecretPayload
    {
        return $this->getCredentialSetsService()->readSecrets($this->requireConnection());
    }

    protected function getCredentialSetsService(): AgentConnectionCredentialSetsService
    {
        /** @var AgentConnectionCredentialSetsService $agentConnectionCredentialSetsService */
        $agentConnectionCredentialSetsService = DDDService::instance()->getService(AgentConnectionCredentialSetsService::class);
        return $agentConnectionCredentialSetsService;
    }

    /** @var string[] The time formats providers answer with (ISO 8601 with or without fractions, RFC 2822 of mail headers) */
    public const array PROVIDER_TIME_FORMATS = [
        DATE_ATOM, 'Y-m-d\\TH:i:s.vP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d\\TH:i:s\\Z', 'Y-m-d\\TH:i:s.v\\Z', DATE_RFC2822, 'D, j M Y H:i:s O', 'j M Y H:i:s O', '!Y-m-d',
    ];

    /** A time a provider sent (ISO 8601, a mail Date header) as a framework DateTime; null when it cannot be read */
    public static function parseProviderTime(?string $providerTime): ?DateTime
    {
        $providerTime = trim((string)$providerTime);
        if ($providerTime === '') {
            return null;
        }
        // a mail Date header may end in a comment, e.g. "+0100 (CET)"
        $providerTime = trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $providerTime));
        $parsedTime = DateTime::fromString($providerTime, static::PROVIDER_TIME_FORMATS);
        return $parsedTime instanceof DateTime ? $parsedTime : null;
    }

    /**
     * Whether the Authentication-Results headers of a received mail prove its From domain. Fails closed:
     * - only the TOPMOST header counts (the receiving server adds it on top; a sender can put any header below), and
     *   only when its authserv-id (the first token) is one of `$trustedAuthenticationServerIds`, the mailbox's own
     *   receiving servers; no trusted id = nothing is proven;
     * - dmarc=pass with header.from equal to the From domain, or dkim=pass with a signing domain (header.d /
     *   header.i) equal to or a parent of the From domain. A dmarc=pass that names no header.from proves nothing.
     *
     * @param string[] $authenticationResultsHeaders the Authentication-Results headers, topmost first (only the first is read)
     * @param string[] $trustedAuthenticationServerIds the authserv-ids of the mailbox's own receiving servers
     */
    public static function isSenderAuthenticated(array $authenticationResultsHeaders, string $fromHeader, array $trustedAuthenticationServerIds): bool
    {
        $fromDomain = '';
        if (preg_match('/@([A-Za-z0-9.\-]+)\s*>?\s*$/', trim($fromHeader), $fromMatches) === 1) {
            $fromDomain = strtolower(rtrim($fromMatches[1], '.'));
        }
        $topmostResults = strtolower(trim((string)($authenticationResultsHeaders[0] ?? '')));
        if ($fromDomain === '' || $topmostResults === '') {
            return false;
        }
        $authenticationServerId = trim(explode(';', $topmostResults, 2)[0]);
        $authenticationServerId = trim(explode(' ', $authenticationServerId, 2)[0]);
        if (!in_array($authenticationServerId, array_map('strtolower', $trustedAuthenticationServerIds), true)) {
            return false;
        }
        if (preg_match('/\bdmarc=pass\b[^;]*header\.from=([a-z0-9.\-]+)/', $topmostResults, $dmarcMatches) === 1 && rtrim($dmarcMatches[1], '.') === $fromDomain) {
            return true;
        }
        if (preg_match_all('/\bdkim=pass\b[^;]*?header\.(?:d|i)=@?([a-z0-9.\-]+)/', $topmostResults, $dkimMatches) > 0) {
            foreach ($dkimMatches[1] as $signingDomain) {
                $signingDomain = rtrim($signingDomain, '.');
                if ($signingDomain === $fromDomain || str_ends_with($fromDomain, '.' . $signingDomain)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Text from a provider, shortened for the model */
    protected static function shorten(string $text, int $maximumCharacters): string
    {
        return mb_strlen($text) > $maximumCharacters ? mb_substr($text, 0, $maximumCharacters) . ' […]' : $text;
    }
}
