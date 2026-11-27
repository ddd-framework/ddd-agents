<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Imap;

use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Infrastructure\Http\PublicUrlGuard;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The gate of a mail server a person typed in (IMAP/SMTP connect): only the standard submission and mailbox ports, and
 * only a host whose every address is public. The client then connects to the checked address (no second DNS lookup)
 * and verifies the certificate against the typed host name (`peer_name`), so neither a private address nor a DNS
 * rebinding reaches an internal service.
 */
#[Exclude]
class MailServerEndpointGuard
{
    public const array IMAP_PORTS = [993];
    public const array SMTP_PORTS = [465, 587];

    /**
     * @param int[] $allowedPorts
     * @return string the checked public IP to connect to
     * @throws AgentConnectionRefusedException
     */
    public static function resolvePublicAddress(string $host, int $port, array $allowedPorts): string
    {
        if (!in_array($port, $allowedPorts, true)) {
            throw new AgentConnectionRefusedException('Port ' . $port . ' is not allowed here; use ' . implode(' or ', $allowedPorts) . '.');
        }
        $host = trim($host);
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
            throw new AgentConnectionRefusedException('The mail server name is not a valid host name.');
        }
        $publicIps = PublicUrlGuard::resolvePublicIps('https://' . $host . '/');
        if ($publicIps === null || $publicIps === []) {
            throw new AgentConnectionRefusedException('The mail server ' . $host . ' does not resolve to public addresses only.');
        }
        return $publicIps[0];
    }

    /** The socket address of a checked IP (IPv6 in brackets) */
    public static function buildSocketAddress(string $scheme, string $ip, int $port): string
    {
        return $scheme . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;
    }
}
