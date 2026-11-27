<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccess;

/**
 * One drive connection as the acting account reaches it: the connection, the reach (owner or release, with its
 * boundaries), its drive and document adapters bound to the connection's token, and the tool call it serves (its
 * space and conversation: the reach and the audience rule of every provider read).
 */
class AgentConnectionDriveReach
{
    public function __construct(
        public AgentConnection $agentConnection,
        public AgentConnectionAccess $agentConnectionAccess,
        public DriveAgentConnectionAdapter $driveAdapter,
        public GoogleDocumentsAgentConnectionAdapter $documentsAdapter,
        public ?AgentToolCallProvenance $callProvenance = null
    ) {
    }
}
