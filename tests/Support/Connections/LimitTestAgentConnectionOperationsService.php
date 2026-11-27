<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Services\Connections\AgentConnectionActionsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService;

/** Opens the send limits of the gate to a test, on a counting audit */
class LimitTestAgentConnectionOperationsService extends AgentConnectionOperationsService
{
    public CountingAgentConnectionActionsService $countingActionsService;

    public function __construct()
    {
        $this->countingActionsService = new CountingAgentConnectionActionsService();
    }

    public function requireLimitsForTest(AgentConnectionOperation $agentConnectionOperation): void
    {
        $this->requireLimits($agentConnectionOperation);
    }

    protected function getActionsService(): AgentConnectionActionsService
    {
        return $this->countingActionsService;
    }
}
