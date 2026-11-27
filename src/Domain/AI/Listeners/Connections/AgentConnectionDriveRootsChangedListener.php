<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Listeners\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What another domain does when the roots of a drive connection changed (a root added, removed, assigned, a release to
 * the agent taken back): a drive that shows the roots as spaces rebuilds them and drops live mounts and cached copies
 * of what is no longer released. Registered by this tag (autoconfigured); best-effort, every access is checked again
 * against the connection anyway.
 */
#[AutoconfigureTag(AgentConnectionDriveRootsChangedListener::TAG)]
interface AgentConnectionDriveRootsChangedListener
{
    public const string TAG = 'ai.connections.drive_roots_changed_listener';

    public function afterDriveRootsChanged(AgentConnection $agentConnection): void;
}
