<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveScopeService;

/**
 * A deferred drive call that first proves its item lies inside the roots of the reach, then calls the adapter: what the
 * operations gate runs after its checks (a callable object instead of a closure). Without an item id it checks that
 * the reach has roots at all.
 */
class AgentConnectionRootedDriveCall
{
    /** @param array<int, mixed> $arguments */
    public function __construct(
        protected AgentConnectionDriveScopeService $agentConnectionDriveScopeService,
        protected DriveAgentConnectionAdapter $driveAdapter,
        protected AgentConnection $agentConnection,
        protected AgentConnectionAccess $agentConnectionAccess,
        protected ?string $itemId,
        protected object $callTarget,
        protected string $methodName,
        protected array $arguments
    ) {
    }

    public function __invoke(): mixed
    {
        if ($this->itemId !== null) {
            $this->agentConnectionDriveScopeService->requireItemInsideRoots($this->driveAdapter, $this->agentConnection, $this->agentConnectionAccess, $this->itemId);
        } else {
            $this->agentConnectionDriveScopeService->requireRootIds($this->agentConnection, $this->agentConnectionAccess);
        }
        return $this->callTarget->{$this->methodName}(...$this->arguments);
    }
}
