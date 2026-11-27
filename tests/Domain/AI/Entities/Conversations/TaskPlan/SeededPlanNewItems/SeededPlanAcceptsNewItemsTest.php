<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SeededPlanNewItems;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentSeededPlanPurposeFacts;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use PHPUnit\Framework\TestCase;

/**
 * A purpose that accepts new items on its seeded plan (`seededTaskPlanAcceptsNewItems`): `update_task_plan` is no
 * longer refused, the agent adds top-level items and subtasks (one per ticket), the seeded items keep their text,
 * place and order and are never dropped, and the planned turn's budget counts the added leaves. Without the flag the
 * seeded plan is still refused a rewrite.
 */
class SeededPlanAcceptsNewItemsTest extends TestCase
{
    public function testTheRewriteRefusalHoldsOnlyWithoutTheFlag(): void
    {
        self::assertNotNull(UpdateTaskPlanTool::findSeededPlanRefusal(AgentSeededPlanPurposeFacts::withSeededPlan(true, false, true)));
        self::assertNull(UpdateTaskPlanTool::findSeededPlanRefusal(AgentSeededPlanPurposeFacts::withSeededPlan(true, false, true, true)));
    }

    public function testThePurposeAcceptsNewItemsOnlyWhenItSeedsAPlan(): void
    {
        $aIConversationPurpose = new AIConversationPurpose();
        $aIConversationPurpose->seededTaskPlanAcceptsNewItems = true;
        self::assertFalse($aIConversationPurpose->acceptsNewItemsOnSeededTaskPlan(), 'no seed, nothing to add to');

        $aIConversationPurpose->seedTaskPlanItems = ['Read the open tickets', 'Report'];
        self::assertTrue($aIConversationPurpose->acceptsNewItemsOnSeededTaskPlan());
    }

    public function testTheAgentAddsItemsAndSubtasksToTheSeededPlan(): void
    {
        $seededPlan = $this->buildSeededPlan();

        $outcome = $seededPlan->applyUpdate(UpdateTaskPlanArguments::fromArguments(['items' => [
            ['key' => '1', 'subtasks' => [['text' => 'Ticket 101'], ['text' => 'Ticket 102']]],
            ['key' => '2'],
            ['text' => 'Ticket 103'],
        ]]), TaskPlanUpdateContext::forTurn(-1));

        self::assertNotNull($outcome->taskPlan, 'applied');
        self::assertSame(4, $outcome->taskPlan->items->countLeafItems(), 'two subtasks under 1, the seeded 2, the new 3');
        $conversation = new AIConversation();
        $conversation->messages = new AIConversationMessages();
        $conversation->agentWorkingState = new AgentWorkingState();
        $conversation->agentWorkingState->taskPlan = $outcome->taskPlan;
        $aIConversationPurpose = new AIConversationPurpose();
        $aIConversationPurpose->stepsPerPlanItem = 6;
        self::assertSame(
            AIConversationTurnBudget::PLANNED_TURN_BASE_STEPS + 6 * 4,
            AIConversationTurnBudget::buildForConversation($conversation, false, null, $aIConversationPurpose)->totalMaxSteps,
            'the budget counts the added leaves'
        );
    }

    public function testTheSeededItemsStayFixed(): void
    {
        $seededPlan = $this->buildSeededPlan();

        $dropped = $seededPlan->checkUpdate(UpdateTaskPlanArguments::fromArguments(['items' => [
            ['key' => '1'], ['key' => '2', 'remove' => true], ['text' => 'Ticket 103'],
        ]]));
        self::assertTrue($dropped->hasRejections(), 'a seeded item is never dropped');

        $renamed = $seededPlan->checkUpdate(UpdateTaskPlanArguments::fromArguments(['items' => [
            ['key' => '1', 'text' => 'Something else'], ['key' => '2'],
        ]]));
        self::assertTrue($renamed->hasRejections(), 'a seeded item keeps its text');

        $reordered = $seededPlan->checkUpdate(UpdateTaskPlanArguments::fromArguments(['items' => [
            ['key' => '2'], ['key' => '1'],
        ]]));
        self::assertTrue($reordered->hasRejections(), 'the seeded items keep their order');
    }

    protected function buildSeededPlan(): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition(['Answer the open tickets', 'Report to the owner']);
        return $taskPlan;
    }
}
