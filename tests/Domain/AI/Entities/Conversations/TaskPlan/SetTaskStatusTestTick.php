<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanChannelConnectionStatesFixture;

/**
 * One `set_task_status` tick in a unit test: the raw arguments parsed ({@see SetTaskStatusArguments}), the evidence built
 * from plain values (no messages = the gates that read the transcript stay off,
 * {@see TaskPlanEvidence::forTurnWithoutTranscript()}), the plan's own application run
 * ({@see TaskPlan::applyStatusUpdates()}) with the given criteria and inputs, and its outcome reported as the result
 * array the tests read. The live call and the supervisor replay call the plan directly.
 *
 * The purpose side is given as the production objects take it: `$taskPlanDoneCriteria` (null = no criterion answers),
 * `$criteriaDecideTicks` (the enforcing purpose's gates G9–G11) and either the channel states of a fixture (null = no
 * channel reader, the connected-channel gate is not applied) or ready-made inputs (the record service's, in the
 * characterization tests).
 */
class SetTaskStatusTestTick
{
    /**
     * @param array<string, mixed> $arguments
     * @param string[] $entitlementLockedToolNames
     * @return array{updatedCount: int, notedCount: int, notedObstacleCount: int, feedback: string[], allAlreadyInStatus: bool, onlyResolvedByPlatformInThisStep: bool}
     */
    public static function apply(
        array $arguments,
        TaskPlan $taskPlan,
        int $currentTurnInitiatorMessageIndex,
        ?AIConversationMessages $conversationMessages = null,
        ?int $widgetSelfHydrationKey = null,
        array $entitlementLockedToolNames = [],
        ?TaskPlanDoneCriteria $taskPlanDoneCriteria = null,
        bool $criteriaDecideTicks = false,
        ?TaskPlanChannelConnectionStatesFixture $channelConnectionStates = null,
        bool $ownerIsOnThePhoneLine = false,
        ?TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs = null,
    ): array {
        $setTaskStatusArguments = SetTaskStatusArguments::fromToolArguments($arguments);
        $taskPlanEvidence = $conversationMessages !== null
            ? TaskPlanEvidence::forTurn($conversationMessages, $currentTurnInitiatorMessageIndex, criteriaDecideTicks: $criteriaDecideTicks, entitlementLockedToolNames: $entitlementLockedToolNames)
            : TaskPlanEvidence::forTurnWithoutTranscript($currentTurnInitiatorMessageIndex, $entitlementLockedToolNames);
        $taskPlanEvidence->criteriaDecideTicks = $criteriaDecideTicks;
        $taskPlanEvidence->widgetSelfHydrationKey = $widgetSelfHydrationKey;
        $taskPlanEvidence->ownerIsOnThePhoneLine = $ownerIsOnThePhoneLine;
        $taskPlanDoneCriterionInputs ??= $channelConnectionStates !== null
            ? TaskPlanDoneCriterionInputsWithFixedChannelStates::forChannelStates($channelConnectionStates, $conversationMessages, $taskPlan->items)
            : TaskPlanDoneCriterionInputs::forConversation(AIConversation::newInstance(), $conversationMessages, $taskPlan->items);
        $agentTaskPlanTickOutcome = $taskPlan->applyStatusUpdates(
            $setTaskStatusArguments,
            $taskPlanEvidence,
            $taskPlanDoneCriteria ?? TaskPlanDoneCriteria::fromClasses(),
            $taskPlanDoneCriterionInputs
        );
        if (!$setTaskStatusArguments->hasUpdatesList) {
            // the key order this result has always had
            return ['updatedCount' => 0, 'feedback' => $agentTaskPlanTickOutcome->feedback, 'notedCount' => 0, 'notedObstacleCount' => 0, 'allAlreadyInStatus' => false, 'onlyResolvedByPlatformInThisStep' => false];
        }
        return [
            'updatedCount' => $agentTaskPlanTickOutcome->updatedCount,
            'notedCount' => $agentTaskPlanTickOutcome->notedCount,
            'notedObstacleCount' => $agentTaskPlanTickOutcome->notedObstacleCount,
            'feedback' => $agentTaskPlanTickOutcome->feedback,
            'allAlreadyInStatus' => $agentTaskPlanTickOutcome->isAllAlreadyInStatus(),
            'onlyResolvedByPlatformInThisStep' => $agentTaskPlanTickOutcome->isOnlyResolvedByPlatformInThisStep(),
        ];
    }
}
