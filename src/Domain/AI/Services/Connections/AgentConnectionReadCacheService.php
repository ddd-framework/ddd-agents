<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCachedProviderCall;
use DDD\Domain\AI\Entities\Connections\AgentConnectionProviderCall;
use DDD\Infrastructure\Cache\Cache;

/**
 * Short-lived cache of provider reads (a search, a list, free/busy). The key is the connection, a generation counter
 * of the connection, the fingerprint of what the reader may see (level and allowlists of its reach,
 * {@see AgentConnectionAccess::fingerprint()}) and the query. Any change of the connection (level, boundaries,
 * release, visibility, pause, disconnect) bumps the generation ({@see invalidateConnection()}), so no reader ever gets
 * a result that its current reach would not give it. Only READ results are cached; a write never is.
 */
class AgentConnectionReadCacheService
{
    public const int DEFAULT_TTL_SECONDS = 120;

    /** The provider call, answered from the cache when an equal read of the same reach is cached */
    public function wrap(AgentConnection $agentConnection, string $accessFingerprint, AgentConnectionProviderCall $providerCall): AgentConnectionCachedProviderCall
    {
        return new AgentConnectionCachedProviderCall($this, $agentConnection, $accessFingerprint, $providerCall);
    }

    public function remember(AgentConnection $agentConnection, string $accessFingerprint, AgentConnectionProviderCall $providerCall, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): mixed
    {
        $cache = Cache::instance();
        $cacheKey = 'agentConnectionRead_' . (int)$agentConnection->id . '_' . $this->getGeneration($agentConnection) . '_' . sha1($accessFingerprint . "\n" . $providerCall->method . "\n" . $providerCall->cacheQuery);
        $cached = $cache->get($cacheKey);
        if ($cached !== null && $cached !== false) {
            return $cached;
        }
        $result = $providerCall();
        $cache->set($cacheKey, $result, $ttlSeconds);
        return $result;
    }

    public function invalidateConnection(AgentConnection|int $agentConnection): void
    {
        $agentConnectionId = $agentConnection instanceof AgentConnection ? (int)$agentConnection->id : $agentConnection;
        Cache::instance()->increment($this->getGenerationKey($agentConnectionId), 1, 86400 * 30);
    }

    protected function getGeneration(AgentConnection $agentConnection): int
    {
        return (int)(Cache::instance()->get($this->getGenerationKey((int)$agentConnection->id)) ?? 0);
    }

    protected function getGenerationKey(int $agentConnectionId): string
    {
        return 'agentConnectionReadGeneration_' . $agentConnectionId;
    }
}
