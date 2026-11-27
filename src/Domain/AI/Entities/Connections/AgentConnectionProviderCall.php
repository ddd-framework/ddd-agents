<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

/**
 * One provider call as an object (no closure): the adapter, its method and the arguments. Invoked by the gate
 * ({@see \DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService::runRead()}) after the checks.
 * With a cache query set, the read is served from the connection-keyed read cache.
 */
class AgentConnectionProviderCall
{
    /** @param array<int, mixed> $arguments */
    public function __construct(
        public readonly object $target,
        public readonly string $method,
        public readonly array $arguments = [],
        public readonly ?string $cacheQuery = null
    ) {
    }

    public static function of(object $target, string $method, mixed ...$arguments): static
    {
        return new static($target, $method, array_values($arguments));
    }

    /** The same call, served from the read cache under this query (reads only) */
    public function cachedAs(string $cacheQuery): static
    {
        return new static($this->target, $this->method, $this->arguments, $cacheQuery);
    }

    public function __invoke(): mixed
    {
        return $this->target->{$this->method}(...$this->arguments);
    }
}
