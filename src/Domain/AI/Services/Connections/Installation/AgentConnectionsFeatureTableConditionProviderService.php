<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Installation;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLink;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTarget;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvent;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThread;
use DDD\Domain\AI\Entities\Connections\AgentConnectionTrigger;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Infrastructure\Services\Service;

/**
 * Tells `app:agent:check-installation` that the AgentConnections tables are required only while AgentConnections is
 * switched on (`AI.agentConnections.enabled`). A host without the feature needs none of them; nothing reads or writes
 * them while it is off.
 */
class AgentConnectionsFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    public const string FEATURE_DESCRIPTION = 'AgentConnections (AI.agentConnections.enabled)';

    /** @var string[] Every database entity of AgentConnections */
    public const array TABLE_ENTITY_CLASSES = [
        AgentConnection::class,
        AgentConnectionAccountLink::class,
        AgentConnectionAction::class,
        AgentConnectionActionTarget::class,
        AgentConnectionAddress::class,
        AgentConnectionApprovalRequest::class,
        AgentConnectionCredentials::class,
        AgentConnectionDriveRoot::class,
        AgentConnectionInboundEvent::class,
        AgentConnectionPermission::class,
        AgentConnectionRelease::class,
        AgentConnectionThread::class,
        AgentConnectionTrigger::class,
    ];

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $isEnabled = AgentConnectionsConfiguration::current()->isEnabled();
        foreach (static::TABLE_ENTITY_CLASSES as $entityClassName) {
            $agentFeatureTableCondition = AgentFeatureTableCondition::create($entityClassName, static::FEATURE_DESCRIPTION, $isEnabled);
            $agentFeatureTableConditions->add($agentFeatureTableCondition);
        }
        return $agentFeatureTableConditions;
    }
}
