<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use PHPUnit\Framework\TestCase;

/**
 * A purpose replaces the planned turn's steps per plan leaf, its ceiling and the turn's cost cap: support work whose
 * items each take several tool calls gets the steps it needs; without the purpose's values the package defaults hold,
 * and explicit run settings still win.
 */
class TurnBudgetOfThePurposeTest extends TestCase
{
    public function testWithoutPurposeValuesAPlannedTurnGetsTheDefaults(): void
    {
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildPlannedConversation(4), false, null, new AIConversationPurpose());

        self::assertSame(AIConversationTurnBudget::PLANNED_TURN_BASE_STEPS + 3 * 4, $turnBudget->totalMaxSteps);
        self::assertSame(AIConversationTurnBudget::PLANNED_TURN_DEFAULT_COST_CAP_USD, $turnBudget->maxTurnCostUsd);
    }

    public function testThePurposesStepsPerItemAndCostCapApply(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $aIConversationPurpose->stepsPerPlanItem = 6;
        $aIConversationPurpose->maxTurnCostUsd = 5.0;

        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildPlannedConversation(4), false, null, $aIConversationPurpose);

        self::assertSame(AIConversationTurnBudget::PLANNED_TURN_BASE_STEPS + 6 * 4, $turnBudget->totalMaxSteps);
        self::assertSame(5.0, $turnBudget->maxTurnCostUsd);
    }

    public function testThePurposesCeilingCapsThePlanAndAToolFloor(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $aIConversationPurpose->stepsPerPlanItem = 6;
        $aIConversationPurpose->plannedTurnMaxStepsCeiling = 90;

        self::assertSame(90, AIConversationTurnBudget::buildForConversation($this->buildPlannedConversation(20), false, null, $aIConversationPurpose)->totalMaxSteps);
        self::assertSame(90, AIConversationTurnBudget::buildForConversation($this->buildPlannedConversation(0), false, 500, $aIConversationPurpose)->totalMaxSteps);
        self::assertSame(
            AIConversationTurnBudget::PLANNED_TURN_MAX_STEPS_CEILING,
            AIConversationTurnBudget::buildForConversation($this->buildPlannedConversation(20), false, null, null)->totalMaxSteps,
            'without a purpose the package ceiling holds'
        );
    }

    public function testThePurposesCostCapAlsoCapsAPlainTurnAndRunSettingsWin(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $aIConversationPurpose->maxTurnCostUsd = 5.0;
        $plainConversation = new AIConversation();
        $plainConversation->messages = new AIConversationMessages();
        self::assertSame(5.0, AIConversationTurnBudget::buildForConversation($plainConversation, false, null, $aIConversationPurpose)->maxTurnCostUsd);

        $runSettings = RunSettings::newInstance();
        $runSettings->maxTurnCostUsd = 1.5;
        $plainConversation->runSettings = $runSettings;
        self::assertSame(1.5, AIConversationTurnBudget::buildForConversation($plainConversation, false, null, $aIConversationPurpose)->maxTurnCostUsd);
    }

    /** A conversation whose task plan, with `$leafCount` open leaves, was written in the current turn. */
    protected function buildPlannedConversation(int $leafCount): AIConversation
    {
        $taskPlanItems = new TaskPlanItems();
        for ($itemNumber = 1; $itemNumber <= max(1, $leafCount); $itemNumber++) {
            $taskPlanItem = new TaskPlanItem();
            $taskPlanItem->key = (string)$itemNumber;
            $taskPlanItem->text = 'Item ' . $itemNumber;
            $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
            $taskPlanItems->add($taskPlanItem);
        }
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        $taskPlan->updatedAtTurnInitiatorMessageIndex = -1;
        $conversation = new AIConversation();
        $conversation->messages = new AIConversationMessages();
        $conversation->agentWorkingState = new AgentWorkingState();
        $conversation->agentWorkingState->taskPlan = $taskPlan;
        return $conversation;
    }
}
