<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;

/** An eligibility in which every space and account may run automated work. */
class AutomationEligibilityServiceWithEverythingEligibleDouble extends AgentAutomationEligibilityService
{
    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        return AgentAutomationEligibility::eligible();
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return false;
    }
}
