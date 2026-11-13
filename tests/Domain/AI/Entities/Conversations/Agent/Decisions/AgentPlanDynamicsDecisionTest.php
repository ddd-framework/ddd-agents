<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlans;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The three bands, the mid-turn late adoption, the directive per band, the finished-plan
 * set (retirement, cap, the read tool) and the question rules of both plan questions.
 */
class AgentPlanDynamicsDecisionTest extends UnitTestCase
{
    public function testTheBandsFollowTheTwoThresholds(): void
    {
        self::assertNull(AgentPlanDynamicsDecision::resolveBand(null));
        self::assertSame(AgentPlanDynamicsDecision::BAND_REQUIRED, AgentPlanDynamicsDecision::resolveBand(0.80));
        self::assertSame(AgentPlanDynamicsDecision::BAND_REQUIRED, AgentPlanDynamicsDecision::resolveBand(0.97));
        self::assertSame(AgentPlanDynamicsDecision::BAND_DIRECT, AgentPlanDynamicsDecision::resolveBand(0.10));
        self::assertSame(AgentPlanDynamicsDecision::BAND_DIRECT, AgentPlanDynamicsDecision::resolveBand(0.0));
        self::assertSame(AgentPlanDynamicsDecision::BAND_MIDDLE, AgentPlanDynamicsDecision::resolveBand(0.11));
        self::assertSame(AgentPlanDynamicsDecision::BAND_MIDDLE, AgentPlanDynamicsDecision::resolveBand(0.79));
    }

    public function testOnlyADecisionModelStampIsAppliedAndTheDirectiveFollowsTheBand(): void
    {
        $decision = new AgentPlanDynamicsDecision();
        $decision->band = AgentPlanDynamicsDecision::BAND_REQUIRED;
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_SHADOW;
        self::assertFalse($decision->isApplied());
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        self::assertTrue($decision->isApplied());
        self::assertStringContainsString('as your FIRST call', (string)$decision->toDirectiveText());
        $decision->band = AgentPlanDynamicsDecision::BAND_DIRECT;
        self::assertStringContainsString('no task plan', (string)$decision->toDirectiveText());
        $decision->band = AgentPlanDynamicsDecision::BAND_MIDDLE;
        self::assertStringContainsString('Start WITHOUT a task plan', (string)$decision->toDirectiveText());
        self::assertStringContainsString('2 earlier plans', (string)$decision->toDirectiveText(2));
        $decision->band = null;
        self::assertNull($decision->toDirectiveText(), 'a fallback without a band renders nothing');
    }

    public function testADirectiveThatAsksForAPlanEndsOnceAPlanUpdateOfTheTurnApplied(): void
    {
        $decision = new AgentPlanDynamicsDecision();
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $decision->band = AgentPlanDynamicsDecision::BAND_REQUIRED;
        self::assertStringContainsString('as your FIRST call', (string)$decision->toDirectiveText(0, null, false));
        self::assertNull($decision->toDirectiveText(0, null, true), 'the plan exists: its own tail entry is the view from here');
        self::assertNull($decision->toDirectiveText(2, '3', true));
        $decision->band = AgentPlanDynamicsDecision::BAND_MIDDLE;
        self::assertNull($decision->toDirectiveText(0, null, true));
        $decision->band = AgentPlanDynamicsDecision::BAND_DIRECT;
        self::assertStringContainsString('no task plan', (string)$decision->toDirectiveText(0, null, true), 'the direct band asks for no plan and stays');
    }

    public function testADirectTurnMayTakeEveryCallItNeeds(): void
    {
        $decision = new AgentPlanDynamicsDecision();
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $decision->band = AgentPlanDynamicsDecision::BAND_DIRECT;
        $directive = (string)$decision->toDirectiveText();
        self::assertStringContainsString('do what it asks — every call it needs —, then answer', $directive);
        self::assertStringNotContainsString('the one thing', $directive);
    }

    public function testAPlanCreatingDirectiveNamesTheDropOfAStandingPlansItems(): void
    {
        $decision = new AgentPlanDynamicsDecision();
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $decision->band = AgentPlanDynamicsDecision::BAND_REQUIRED;
        self::assertSame(
            '[SIGNAL:plan] This request needs a working plan: several separately accountable changes or deliverables, or work that must survive a pause.'
                . ' Create it with update_task_plan as your FIRST call (one item per part), then work the items; tick each once its result is in your context,'
                . ' with the call for the next item or with your answer. A plan from an earlier task still stands: in that same update_task_plan call drop each of its items by its key'
                . ' (for item 3: {"key": "3", "remove": true}) and add the new items.',
            $decision->toDirectiveText(0, '3')
        );
        self::assertStringNotContainsString('earlier task', (string)$decision->toDirectiveText(), 'no plan stands: the creation needs no drop');
        $decision->band = AgentPlanDynamicsDecision::BAND_MIDDLE;
        self::assertStringEndsWith(AgentPlanDynamicsDecision::buildEarlierPlanStandsSentence('3'), (string)$decision->toDirectiveText(0, '3'));
        $decision->band = AgentPlanDynamicsDecision::BAND_DIRECT;
        self::assertStringNotContainsString('earlier task', (string)$decision->toDirectiveText(0, '3'), 'an answer without a plan drops nothing');
    }

    public function testTheEarlierPlanKeyIsNamedOnlyForAPlanThatStoodAtTheDecisionAndOnlyUntilAPlanUpdateApplied(): void
    {
        $standingPlan = new TaskPlan();
        $standingPlan->items = TaskPlanItems::fromPurposeSeedDefinition(['Seeded first']);
        $droppableItem = new TaskPlanItem();
        $droppableItem->key = '2';
        $droppableItem->text = 'Answer the reviews';
        $standingPlan->items->add($droppableItem);

        $decision = AgentPlanDynamicsDecision::build(4);
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $decision->band = AgentPlanDynamicsDecision::BAND_REQUIRED;
        // a plan created after the decision is no earlier plan
        $decision->recordPlanStandingAtDecision(null);
        self::assertNull($decision->resolveEarlierPlanItemKeyToDrop($standingPlan, false));
        self::assertStringNotContainsString('earlier task', (string)$decision->buildStartOutcome('on', 0, null)['directive']);

        $decision->recordPlanStandingAtDecision($standingPlan);
        self::assertTrue($decision->earlierPlanStoodAtDecision);
        self::assertSame('2', $decision->resolveEarlierPlanItemKeyToDrop($standingPlan, false), 'the first key a call may drop — never the seeded one');
        self::assertNull($decision->resolveEarlierPlanItemKeyToDrop($standingPlan, true), 'a plan update of this turn applied: the drop is done');
        self::assertSame($decision->toDirectiveText(0, '2'), $decision->buildStartOutcome('on', 0, $standingPlan)['directive']);
    }

    public function testTheSliceRoundTrips(): void
    {
        $decision = new AgentPlanDynamicsDecision();
        $decision->ownerMessageIndex = 7;
        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $decision->needsWorkingPlanProbability = 0.42;
        $decision->band = AgentPlanDynamicsDecision::BAND_MIDDLE;
        $decision->retiredFinishedPlan = true;
        $decision->earlierPlanStoodAtDecision = true;
        $reloaded = new AgentPlanDynamicsDecision();
        $reloaded->mapFromRepository(json_decode((string)json_encode($decision->mapToRepository()), true));
        self::assertSame($decision->mapToRepository(), $reloaded->mapToRepository());
    }

    protected function finishedPlan(string $title, array $itemTexts): TaskPlan
    {
        $items = new TaskPlanItems();
        foreach ($itemTexts as $text) {
            $item = new TaskPlanItem();
            $item->text = $text;
            $item->status = TaskPlanItem::STATUS_DONE;
            $item->statusNote = 'done: ' . $text;
            $items->add($item);
        }
        $plan = new TaskPlan();
        $plan->items = $items;
        $plan->createdAtMessageIndex = 3;
        $plan->retire(9, '2026-09-26 12:00:00', $title);
        return $plan;
    }

    public function testAFinishedPlanRetiresIntoTheSetAndClearsTheActiveSlot(): void
    {
        $state = new AgentWorkingState();
        $openItem = new TaskPlanItem();
        $openItem->text = 'still open';
        $openItem->status = TaskPlanItem::STATUS_OPEN;
        $items = new TaskPlanItems();
        $items->add($openItem);
        $state->taskPlan = new TaskPlan();
        $state->taskPlan->items = $items;
        self::assertNull($state->retireFinishedTaskPlan(5, '2026-09-26 12:00:00'), 'an open item keeps the plan active');
        self::assertNotNull($state->taskPlan);

        $theItem = $state->taskPlan->items->first();
        self::assertNotNull($theItem);
        $theItem->status = TaskPlanItem::STATUS_DONE;
        $theItem->statusNote = 'produced';
        $retired = $state->retireFinishedTaskPlan(5, '2026-09-26 12:00:00', 'Trag bei Google ein …');
        self::assertNotNull($retired);
        self::assertNull($state->taskPlan, 'the active slot is cleared');
        self::assertSame(1, $state->countFinishedTaskPlans());
        self::assertSame(5, $retired->completedAtMessageIndex);
        self::assertSame('Trag bei Google ein …', $retired->title);
        self::assertSame('done: still open — produced', $retired->outcomeSummary);

        // the slice carries the set and round-trips
        $repository = json_decode((string)json_encode($state->mapToRepository()), true);
        self::assertCount(1, $repository['completedTaskPlans']);
        $reloaded = new AgentWorkingState();
        $reloaded->mapFromRepository($repository);
        self::assertSame(1, $reloaded->countFinishedTaskPlans());
        self::assertSame('Trag bei Google ein …', $reloaded->completedTaskPlans?->first()?->title);
        self::assertNull($reloaded->taskPlan);
    }

    public function testTheNewestPlanIsTheActiveOneElseTheLastRetiredOne(): void
    {
        $state = new AgentWorkingState();
        self::assertNull($state->getNewestTaskPlan(), 'a conversation that never planned has none');

        $state->completedTaskPlans = new TaskPlans();
        $state->completedTaskPlans->addFinishedPlan($this->finishedPlan('first', ['a']));
        $state->completedTaskPlans->addFinishedPlan($this->finishedPlan('second', ['b', 'c']));
        self::assertSame('second', $state->getNewestTaskPlan()?->title, 'after the retirement the last finished plan is the one the turn worked on');

        $state->taskPlan = new TaskPlan();
        $state->taskPlan->title = 'active';
        self::assertSame('active', $state->getNewestTaskPlan()?->title);
    }

    public function testTheFinishedSetIsCappedOldestFirstAndTheToolListsNewestFirst(): void
    {
        $set = new TaskPlans();
        for ($i = 1; $i <= TaskPlans::MAX_FINISHED_PLANS + 2; $i++) {
            $set->addFinishedPlan($this->finishedPlan('plan ' . $i, ['item ' . $i]));
        }
        self::assertSame(TaskPlans::MAX_FINISHED_PLANS, $set->count());
        self::assertSame('plan 3', $set->first()?->title, 'the two oldest dropped');
        $numbered = $set->newestFirstNumbered();
        self::assertSame('plan ' . (TaskPlans::MAX_FINISHED_PLANS + 2), $numbered[1]->title);

        $list = GetTaskPlansTool::buildResult([], $set);
        self::assertStringStartsWith('Finished task plans (newest first):', $list);
        self::assertStringContainsString('#1 · finished 2026-09-26 12:00:00 · "plan 22" — 1 item: 1 done, 0 blocked, 0 open', $list);
        $detail = GetTaskPlansTool::buildResult(['planNumber' => 2], $set);
        self::assertStringContainsString('"plan 21"', $detail);
        self::assertStringContainsString('[✓] 1 item 21 — done: item 21', $detail);
        self::assertStringContainsString('use a number from 1 to 20', GetTaskPlansTool::buildResult(['planNumber' => 99], $set));
        self::assertSame('No finished task plan exists in this conversation yet.', GetTaskPlansTool::buildResult([], null));
    }

    public function testTheTailHeaderIsTheSameForEveryPlan(): void
    {
        $item = new TaskPlanItem();
        $item->text = 'a';
        $item->status = TaskPlanItem::STATUS_OPEN;
        $items = new TaskPlanItems();
        $items->add($item);
        $plan = new TaskPlan();
        $plan->items = $items;
        // one header — the plan-dynamics switch and the seed no longer pick a variant
        self::assertStringContainsString(TaskPlan::buildTickRules(), $plan->toTailHintText());
        $modelPlanHeader = $plan->toTailHintText();
        $item->seededByPurpose = true;
        self::assertSame($modelPlanHeader, $plan->toTailHintText(), 'a seeded plan reads the same header');
    }

    public function testThePlanQuestionsFollowTheQuestionRules(): void
    {
        $questions = ArgusAgentTurnStartDecision::buildPlanDynamicsQuestions();
        self::assertArrayHasKey(ArgusAgentTurnStartDecision::QUESTION_NEEDS_WORKING_PLAN, $questions);
        $question = $questions[ArgusAgentTurnStartDecision::QUESTION_NEEDS_WORKING_PLAN];
        self::assertSame('noul', $question['type']);
        self::assertStringContainsString('`' . ArgusAgentTurnStartDecision::STATE_OWNER_MESSAGE . '`', $question['instructions']);
        self::assertStringContainsString('`' . ArgusAgentTurnStartDecision::STATE_FINISHED_PLANS . '`', $question['instructions']);
        self::assertStringContainsString('SEVERAL separate changes', $question['criteria']['true']);
        self::assertStringContainsString('however many look-ups it takes', $question['criteria']['false'], 'the traps live in the false branch');
        self::assertStringContainsString('one action together with its check', $question['criteria']['false']);
        self::assertStringContainsString('ONE deliverable even when it has several sections', $question['criteria']['false']);

        // the folded request carries the plan site only when requested, with its own state keys
        $request = new ArgusAgentTurnStartDecision();
        self::assertFalse($request->carriesPlanDynamicsSite());
        $request->planDynamicsSiteRequested = true;
        $request->planOwnerMessage = 'Trag ein …';
        $request->planFinishedPlans = ['#1 · finished …'];
        self::assertTrue($request->carriesPlanDynamicsSite());
        $state = $request->buildState();
        self::assertSame('Trag ein …', $state[ArgusAgentTurnStartDecision::STATE_OWNER_MESSAGE]);
        self::assertSame(['#1 · finished …'], $state[ArgusAgentTurnStartDecision::STATE_FINISHED_PLANS]);
        self::assertSame([ArgusAgentTurnStartDecision::QUESTION_NEEDS_WORKING_PLAN], ArgusAgentTurnStartDecision::questionNamesForSite('planDynamics'));
    }
}
