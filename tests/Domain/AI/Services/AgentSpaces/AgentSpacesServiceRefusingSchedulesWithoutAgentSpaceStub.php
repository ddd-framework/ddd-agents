<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;

/** The space service of an application that allows no schedule without a space, with its own refusal text. */
class AgentSpacesServiceRefusingSchedulesWithoutAgentSpaceStub extends AgentSpacesService
{
    public const string SCHEDULE_WITHOUT_AGENT_SPACE_NOT_ALLOWED_MESSAGE = 'A schedule needs a shop.';

    public function isScheduleWithoutAgentSpaceAllowed(AIConversationSchedule $schedule): bool
    {
        return false;
    }
}
