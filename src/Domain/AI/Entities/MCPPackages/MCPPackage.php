<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Cache\Cache;

/**
 * Static registry describing a single MCP package — a group of #[MCPTool] methods.
 *
 * Definitions are code (static $name/$description). The generated documentation is
 * deploy-invariant but expensive to reflect, so {@see self::buildDocumentation()}
 * memoizes its result in Redis (see {@see MCPBundle} for the invalidation caveat).
 */
class MCPPackage extends ValueObject
{
    /** @var string|null A unique name of this MCP Package */
    public static ?string $name = null;

    /** @var string|null Description of the MCP Package */
    public static ?string $description = null;

    public static function buildDocumentation(): MCPPackageDocumentation
    {
        $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
        $cacheKey = 'mcp.doc.package.' . str_replace('\\', '.', static::class);

        $cached = $cache->get($cacheKey);
        if ($cached instanceof MCPPackageDocumentation) {
            return $cached;
        }

        $documentation = MCPPackageDocumentation::createFromClassName(static::class);
        $cache->set($cacheKey, $documentation, Cache::CACHE_TTL_ONE_DAY);

        return $documentation;
    }
}
