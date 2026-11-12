<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanChannelConnectionStatesFixture;

/**
 * Inputs whose connected-channel reader answers from a fixed map ({@see TaskPlanChannelConnectionStatesFixture}, which
 * records every channel asked) — the stand-in for the purpose's live read over a freshly loaded shop.
 */
class TaskPlanDoneCriterionInputsWithFixedChannelStates extends TaskPlanDoneCriterionInputs
{
    public TaskPlanChannelConnectionStatesFixture $channelConnectionStates;

    public static function forChannelStates(TaskPlanChannelConnectionStatesFixture $channelConnectionStates, ?AIConversationMessages $conversationMessages, ?TaskPlanItems $taskPlanItems): self
    {
        $taskPlanDoneCriterionInputs = self::forConversation(AIConversation::newInstance(), $conversationMessages, $taskPlanItems);
        $taskPlanDoneCriterionInputs->channelConnectionStates = $channelConnectionStates;
        return $taskPlanDoneCriterionInputs;
    }

    public function readsChannelConnections(): bool
    {
        return true;
    }

    public function findChannelConnectionState(string $connectedChannel): ?bool
    {
        return $this->channelConnectionStates->findConnectionState($connectedChannel);
    }
}
