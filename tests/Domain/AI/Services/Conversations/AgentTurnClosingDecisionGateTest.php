<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure gate of the turn-closing decision ({@see AgentCheckedStopPolicy::shouldAskTurnClosingDecision()}): the decision model is asked only where its verdict could matter and would be safe.
 */
class AgentTurnClosingDecisionGateTest extends UnitTestCase
{
    protected function taskPlan(bool $gatedOpenItem = true, bool $blockedOnOwner = false, ?int $resolvedAt = null): TaskPlan
    {
        $taskPlanItems = new TaskPlanItems();
        $item = new TaskPlanItem();
        $item->text = 'add the categories';
        $item->status = TaskPlanItem::STATUS_OPEN;
        $item->doneRequiresToolCall = $gatedOpenItem ? 'updateCategories' : null;
        $taskPlanItems->add($item);
        if ($blockedOnOwner) {
            $blocked = new TaskPlanItem();
            $blocked->text = 'verify the profile';
            $blocked->status = TaskPlanItem::STATUS_BLOCKED;
            $blocked->requiresUserAction = true;
            $taskPlanItems->add($blocked);
        }
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        $taskPlan->resolvedAtTurnInitiatorMessageIndex = $resolvedAt;
        return $taskPlan;
    }

    /** @param array<string, mixed> $overrides */
    protected function gate(array $overrides = []): bool
    {
        $agentService = self::instantiateWithoutConstructor(AIConversationAgentService::class);
        $arguments = array_merge([
            'taskPlan' => $this->taskPlan(),
            'currentTurnInitiatorMessageIndex' => 7,
            'bounceSignalsInCurrentChunk' => 0,
            'isFoldTurn' => false,
            'workToolRanThisTurn' => false,
            'answerText' => 'Ich lade jetzt die Kategorien.',
        ], $overrides);
        return (bool)AgentCheckedStopPolicy::shouldAskTurnClosingDecision(...array_values($arguments));
    }

    public function testTheModelIsAskedOnAnAnnounceShapedTurnWithGatedOpenWork(): void
    {
        self::assertTrue($this->gate());
    }

    public function testNoPlanNoGatedItemOrOwnerBlockedItemMeansNoQuestion(): void
    {
        self::assertFalse($this->gate(['taskPlan' => null]));
        self::assertFalse($this->gate(['taskPlan' => $this->taskPlan(gatedOpenItem: false)]));
        self::assertFalse($this->gate(['taskPlan' => $this->taskPlan(blockedOnOwner: true)]));
    }

    public function testADeliveredOrResolvedOrFoldOrCappedTurnIsNeverJudged(): void
    {
        self::assertFalse($this->gate(['workToolRanThisTurn' => true]));
        self::assertFalse($this->gate(['taskPlan' => $this->taskPlan(resolvedAt: 7)]));
        self::assertTrue($this->gate(['taskPlan' => $this->taskPlan(resolvedAt: 5)]));
        self::assertFalse($this->gate(['isFoldTurn' => true]));
        self::assertFalse($this->gate(['bounceSignalsInCurrentChunk' => AgentCheckedStopPolicy::MAX_BOUNCES_PER_CHUNK]));
        self::assertFalse($this->gate(['answerText' => '   ']));
    }
}
