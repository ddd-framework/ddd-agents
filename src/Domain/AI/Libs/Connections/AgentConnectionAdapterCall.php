<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections;

/**
 * One deferred adapter call (`$adapter->$methodName(...$arguments)`) for the operations gate, which runs it only after
 * its checks: a callable object instead of a closure.
 */
class AgentConnectionAdapterCall
{
    /** @param array<int, mixed> $arguments */
    public function __construct(protected object $adapter, protected string $methodName, protected array $arguments)
    {
    }

    public function __invoke(): mixed
    {
        return $this->adapter->{$this->methodName}(...$this->arguments);
    }
}
