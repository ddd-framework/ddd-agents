<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

/**
 * A fixed map connected channel → connection state (true connected, false not connected, null unreadable), the answer of
 * the tick's channel reader in a test ({@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\TaskPlanDoneCriterionInputsWithFixedChannelStates}). Records
 * every channel asked, in order.
 */
class TaskPlanChannelConnectionStatesFixture
{
    /** @var string[] */
    public array $askedChannels = [];

    /** @param array<string, bool|null> $connectionStateByChannel */
    public function __construct(protected array $connectionStateByChannel)
    {
    }

    public function __invoke(string $connectedChannel): ?bool
    {
        return $this->findConnectionState($connectedChannel);
    }

    public function findConnectionState(string $connectedChannel): ?bool
    {
        $this->askedChannels[] = $connectedChannel;
        return $this->connectionStateByChannel[$connectedChannel] ?? null;
    }
}
