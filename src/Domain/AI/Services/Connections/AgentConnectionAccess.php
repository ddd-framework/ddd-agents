<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;

/**
 * How one account reaches one connection in one space: as its owner (mode 1), or through a release to a space it
 * works in. Carries the cap on the level and the boundaries that hold for this reach; the boundaries of the
 * connection itself always hold as well.
 */
#[Exclude]
class AgentConnectionAccess
{
    public const string KIND_OWNER = 'OWNER';
    public const string KIND_RELEASE = 'RELEASE';

    public string $kind = self::KIND_OWNER;
    public ?AgentConnectionRelease $agentConnectionRelease = null;

    public static function asOwner(): static
    {
        return new static();
    }

    public static function throughRelease(AgentConnectionRelease $agentConnectionRelease): static
    {
        $agentConnectionAccess = new static();
        $agentConnectionAccess->kind = self::KIND_RELEASE;
        $agentConnectionAccess->agentConnectionRelease = $agentConnectionRelease;
        return $agentConnectionAccess;
    }

    /** The ceiling of a capability for this reach: none for the owner, the release's per-capability ceiling else */
    public function getCeiling(string $capability): string
    {
        return $this->agentConnectionRelease === null ? AgentConnectionPermission::LEVEL_SEND_BY_RULE : $this->agentConnectionRelease->getCeilingOfCapability($capability);
    }

    /**
     * The allowed values of one boundary kind for this reach: the connection's own and the release's, both hold (an
     * empty list on one side is no limit there). Null = no limit at all. A side with a boundary that cannot be read
     * allows nothing (fail closed).
     *
     * @return string[]|null
     */
    public function getAllowedBoundaryValues(AgentConnection $agentConnection, string $kind): ?array
    {
        if (($agentConnection->scopeBoundaries ?? null)?->hasUnreadableBoundary()
            || ($this->agentConnectionRelease?->scopeBoundaries ?? null)?->hasUnreadableBoundary()) {
            return [];
        }
        $ofConnection = $agentConnection->getScopeBoundaryValues($kind);
        $ofRelease = $this->agentConnectionRelease?->getScopeBoundaryValues($kind) ?? [];
        if ($ofConnection === [] && $ofRelease === []) {
            return null;
        }
        if ($ofConnection === []) {
            return $ofRelease;
        }
        if ($ofRelease === []) {
            return $ofConnection;
        }
        return array_values(array_intersect($ofConnection, $ofRelease));
    }

    /** A fingerprint of what this reach may see, for cache keys (connection + boundaries + level) */
    public function fingerprint(AgentConnection $agentConnection): string
    {
        return hash('sha256', implode('|', [
            $agentConnection->id, ($agentConnection->scopeBoundaries ?? null)?->fingerprintText(), $this->kind,
            $this->agentConnectionRelease?->id, ($this->agentConnectionRelease?->scopeBoundaries ?? null)?->fingerprintText(),
            ($this->agentConnectionRelease?->capabilityCeilings ?? null)?->fingerprintText(),
        ]));
    }
}
