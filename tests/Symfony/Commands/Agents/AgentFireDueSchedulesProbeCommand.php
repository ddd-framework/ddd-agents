<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Symfony\Commands\Agents\AgentFireDueSchedulesCommand;

/**
 * The fire command with a schedules service handed in instead of the container's: the method the test double
 * overrides is the command's own accessor of the service.
 */
class AgentFireDueSchedulesProbeCommand extends AgentFireDueSchedulesCommand
{
    /** @var string The name of the probe; its own, so its lock never meets the real command's */
    public const string PROBE_COMMAND_NAME = 'app:test:fire-due-schedules-probe';

    /**
     * @param AIConversationSchedulesService $schedulesServiceDouble
     */
    public function __construct(protected AIConversationSchedulesService $schedulesServiceDouble)
    {
        // a subclass without its own attribute has no name, and the lock of the command is keyed by the name
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getAIConversationSchedulesService(): AIConversationSchedulesService
    {
        return $this->schedulesServiceDouble;
    }
}
