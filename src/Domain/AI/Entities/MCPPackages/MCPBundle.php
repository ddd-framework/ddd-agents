<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Cache\Cache;

/**
 * Static registry describing an MCP "server" — a named group of MCPPackages.
 *
 * Definitions are code (static $name/$description + INCLUDED_PACKAGES), not DB data.
 * The generated documentation is derived purely from code (DTO reflection) and is
 * therefore deploy-invariant but expensive to build, so {@see self::buildDocumentation()}
 * memoizes its result in Redis.
 */
class MCPBundle extends ValueObject
{
    /** @var string[] MCPPackage classes included in this bundle */
    public const array INCLUDED_PACKAGES = [];

    /** @var string|null A unique name of this MCP Bundle */
    public static ?string $name = null;

    /** @var string|null Description of the MCP Bundle */
    public static ?string $description = null;

    /**
     * Builds — and caches in Redis — the MCP documentation for this bundle.
     *
     * The schema is a pure function of the bundle's code (reflected to $maxDepth), so it
     * is stable for a given composition. The cache key is derived from the included
     * packages (see {@see self::buildDocumentationCacheKey()}), so adding/removing a
     * package invalidates the cache automatically.
     */
    public static function buildDocumentation(bool $includeOutputSchema = false, bool $addObjectsToDef = false, int $maxDepth = 6, bool $noCache = false): MCPBundleDocumentation
    {
        $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
        $cacheKey = static::buildDocumentationCacheKey($includeOutputSchema, $addObjectsToDef, $maxDepth);

        // $noCache bypasses the cached entry and rebuilds from source, then overwrites the cache (invalidation +
        // refresh). Used by the documentation endpoint's `?noCache` param while iterating on tool schemas.
        if (!$noCache) {
            $cached = $cache->get($cacheKey);
            if ($cached instanceof MCPBundleDocumentation) {
                return $cached;
            }
        }

        $documentation = MCPBundleDocumentation::createFromClassName(
            static::class,
            $includeOutputSchema,
            $addObjectsToDef,
            $maxDepth
        );
        $cache->set($cacheKey, $documentation, Cache::CACHE_TTL_ONE_DAY);

        return $documentation;
    }

    /**
     * Drops the cached documentation for the four flag permutations at the given depth.
     * Call after changing tool signatures / referenced DTOs within an included package,
     * since those changes are not encoded in the cache key (only package composition is).
     */
    public static function clearDocumentationCache(int $maxDepth = 6): void
    {
        $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
        foreach ([false, true] as $includeOutputSchema) {
            foreach ([false, true] as $addObjectsToDef) {
                $cache->delete(static::buildDocumentationCacheKey($includeOutputSchema, $addObjectsToDef, $maxDepth));
            }
        }
    }

    /**
     * Cache key derived from the bundle's composition — the set of included packages —
     * plus the flags that change the generated output. Adding/removing a package changes
     * the key (and thus busts the cache) without touching the bundle class itself.
     *
     * NOTE: changes *inside* an included package (a new #[MCPTool] method, a changed DTO
     * property) are NOT encoded here — rely on deploy flush / TTL / clearDocumentationCache()
     * for those.
     */
    protected static function buildDocumentationCacheKey(
        bool $includeOutputSchema,
        bool $addObjectsToDef,
        int $maxDepth
    ): string
    {
        $includedPackages = static::INCLUDED_PACKAGES;
        sort($includedPackages);
        $packagesSignature = substr(md5(implode('|', $includedPackages)), 0, 12);

        return 'mcp.doc.bundle.' . str_replace('\\', '.', static::class)
            . '.' . $packagesSignature
            . '.os' . (int)$includeOutputSchema
            . '.od' . (int)$addObjectsToDef
            . '.d' . $maxDepth;
    }
}
