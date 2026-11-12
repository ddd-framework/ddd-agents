<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The plan-dynamics decision site (layer L2): does this owner turn need a working
 * plan? Its question rides the folded turn-start request ({@see buildRequestInput()} → {@see applyDecision()}; the
 * former mid-turn re-ask is gone). Before the question, the plan the previous owner turn finished reopens
 * for the owner's very next message ({@see reopenFinishedTaskPlanOfThePreviousOwnerTurnIfDue()}). The bands, thresholds
 * and directive texts are {@see AgentPlanDynamicsDecision}'s.
 */
class AgentPlanDynamicsDecisionsService extends Service
{
    use AITraceLogTrait;

    /**
     * REOPEN the plan the previous owner turn finished when
     * this turn's message is the owner's very next one ({@see TaskPlan::reopensForOwnerTurn()}) — it becomes the active
     * plan again, so the parts the owner adds join it as new items instead of a second plan. The turn is stamped by the
     * platform ({@see AgentPlanDynamicsDecision::buildForReopenedPlan()}): its directive names the reopening, and the
     * turn-start question is not asked ({@see buildRequestInput()} finds the turn decided — an active plan stands). Only
     * where a finished plan can exist: site `on` (a plan retires only there), a root conversation whose purpose neither
     * seeds its plan nor works autonomously, not schedule-bound, no active plan, a turn opened by the owner's text. The
     * active plan, the finished set and the stamp are persisted in ONE fenced slice write (the retirement cleared both plan
     * markers, a finished plan has no open item to set one); the retirement at this turn's accepted final answer closes
     * the plan again ({@see AIConversationTaskPlanRecordService::retireFinishedTaskPlanIfDue()}). Fail-open: any other
     * error leaves the turn as it was.
     *
     * @param bool $conversationWorksAutonomously the purpose works on after its kickoff
     * @param bool $conversationSeedsATaskPlan the purpose seeds its own plan
     * @return bool true when the plan was reopened
     * @throws AgentRunClaimLostException the run claim was lost while the slices were written
     */
    public function reopenFinishedTaskPlanOfThePreviousOwnerTurnIfDue(
        AIConversation $conversation,
        AgentDecisionModelSettings $decisionModelSettings,
        bool $conversationWorksAutonomously,
        bool $conversationSeedsATaskPlan,
        ?string $runToken
    ): bool {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
            if ($siteMode !== AgentDecisionModelSettings::MODE_ON || $conversation->isDelegation() || !isset($conversation->id)
                || $conversation->isScheduleBound() || $conversationWorksAutonomously || $conversationSeedsATaskPlan
            ) {
                return false;
            }
            $agentWorkingState = $conversation->getAgentWorkingState();
            $newestFinishedPlan = $agentWorkingState?->completedTaskPlans?->last();
            if ($agentWorkingState === null || $agentWorkingState->taskPlan !== null || !$newestFinishedPlan instanceof TaskPlan) {
                return false;
            }
            $initiatorMessage = $conversation->messages->getNewestInitiatorMessage();
            if ($initiatorMessage === null || !$initiatorMessage->isUserRoleText() || trim((string)($initiatorMessage->message ?? '')) === '') {
                return false; // a signal-started turn, a widget click — not the owner's text
            }
            $initiatorMessageIndex = (int)$initiatorMessage->indexInConversation;
            if ($agentWorkingState->planDynamicsDecision?->ownerMessageIndex === $initiatorMessageIndex
                || !$newestFinishedPlan->reopensForOwnerTurn($conversation->messages, $initiatorMessageIndex)
            ) {
                return false;
            }
            $reopenedTaskPlan = $agentWorkingState->reopenNewestFinishedTaskPlan();
            if ($reopenedTaskPlan === null) {
                return false;
            }
            $agentWorkingState->planDynamicsDecision = AgentPlanDynamicsDecision::buildForReopenedPlan($initiatorMessageIndex, $reopenedTaskPlan);
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'taskPlan', 'completedTaskPlans', 'planDynamicsDecision');
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $aIConversationTurnEventsService->recordEventOfConversation(
                $conversation,
                AIConversationTurnEvent::KIND_DECISION,
                AgentDecisionModelSettings::SITE_PLAN_DYNAMICS,
                $initiatorMessageIndex,
                'reopening',
                null,
                ['applied' => true, 'mode' => $siteMode, 'reopenedFinishedPlan' => true, 'itemCount' => $reopenedTaskPlan->items?->countAllItems() ?? 0, 'finishedPlanCount' => $agentWorkingState->countFinishedTaskPlans()],
                $runToken,
                null
            );
            $this->aiTrace('turn.planDynamics.reopened', ['conversationId' => $conversation->id ?? null, 'title' => $reopenedTaskPlan->title, 'ownerMessageIndex' => $initiatorMessageIndex]);
            return true;
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $reopenError) {
            $this->aiTrace('turn.planDynamics.reopenFailed', ['conversationId' => $conversation->id ?? null, 'message' => $reopenError->getMessage()]);
            return false;
        }
    }

    /**
     * The site's turn-start request input, or null when the site does not run for this turn: site `off` (switch 1 no,
     * the global off, the kill switch), a delegation child, a purpose that SEEDS its plan or works autonomously
     * (the plan is mandatory there), a schedule-bound run (a scheduled firing always creates its
     * plan first), a signal-started turn, a chunk resume of an already decided owner turn, or — THE owner
     * rule — an ACTIVE plan that still has owed open items ("while a plan with remaining items is active, the decision model is not
     * asked"). Under `shadow` the question is asked and recorded, the model receives nothing. Fail-open (null).
     *
     * @param bool $conversationWorksAutonomously the purpose works on after its kickoff
     * @param bool $conversationSeedsATaskPlan the purpose seeds its own plan
     */
    public function buildRequestInput(
        AIConversation $conversation,
        AgentDecisionModelSettings $decisionModelSettings,
        bool $conversationWorksAutonomously,
        bool $conversationSeedsATaskPlan
    ): ?AgentPlanDynamicsDecisionQuestion {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || !isset($conversation->id)) {
                return null;
            }
            if ($conversation->isScheduleBound() || $conversationWorksAutonomously || $conversationSeedsATaskPlan) {
                return null;
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            if ($initiatorMessageIndex === null) {
                return null;
            }
            $agentWorkingState = $conversation->getAgentWorkingState();
            $previousDecision = $agentWorkingState?->planDynamicsDecision;
            if ($previousDecision !== null && $previousDecision->ownerMessageIndex === $initiatorMessageIndex) {
                return null; // a chunk resume of the same owner turn — the band stands
            }
            $activePlan = $agentWorkingState?->taskPlan;
            if ($activePlan !== null && ($activePlan->items?->hasOwedOpenItems() ?? false)) {
                return null; // the rule: an active plan with remaining items is never re-questioned
            }
            $initiatorMessage = $conversation->messages->getNewestInitiatorMessage();
            if ($initiatorMessage === null
                || !$initiatorMessage->isUserRoleText()
                || trim((string)($initiatorMessage->message ?? '')) === ''
            ) {
                return null; // a signal-started turn, a widget click — not the owner's text
            }
            $recentTurnTexts = $conversation->messages->buildOwnerTurns()->buildRecentTurnTextsBefore($initiatorMessageIndex);
            $finishedPlans = [];
            foreach ($agentWorkingState?->completedTaskPlans?->newestFirstNumbered() ?? [] as $number => $finishedPlan) {
                $finishedPlans[] = $finishedPlan->toFinishedLine((int)$number);
            }
            $planDynamicsQuestion = new AgentPlanDynamicsDecisionQuestion();
            $planDynamicsQuestion->mode = $siteMode;
            $planDynamicsQuestion->initiatorMessageIndex = $initiatorMessageIndex;
            $planDynamicsQuestion->ownerMessage = (string)$initiatorMessage->message;
            $planDynamicsQuestion->assistantTextBefore = $recentTurnTexts->assistantTextBefore;
            $planDynamicsQuestion->recentTurns = $recentTurnTexts->recentTurns;
            $planDynamicsQuestion->finishedPlans = $finishedPlans;
            return $planDynamicsQuestion;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.planDynamics.inputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    /**
     * Parse the plan answer of the folded turn-start request, resolve the band, stamp the slice (applied only under
     * `on`), record the decision and its event.
     *
     * @param string|null $requestDecidedBy the batch's own fallback, null when the request answered
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function applyDecision(
        AIConversation $conversation,
        AgentPlanDynamicsDecisionQuestion $planDynamicsQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): void {
        $initiatorMessageIndex = $planDynamicsQuestion->initiatorMessageIndex;
        $decision = AgentPlanDynamicsDecision::build($initiatorMessageIndex);
        $decidedBy = $requestDecidedBy;
        if ($decidedBy === null) {
            if ($argusDecision === null || !$argusDecision->planDynamicsAnswersUsable()) {
                $decidedBy = AgentPlanDynamicsDecision::FALLBACK_UNPARSABLE;
            } else {
                $decision->needsWorkingPlanProbability = $argusDecision->needsWorkingPlanProbability;
                $decision->band = AgentPlanDynamicsDecision::resolveBand($decision->needsWorkingPlanProbability);
                $decision->modelVersion = $argusDecision->modelVersion;
                $decidedBy = $planDynamicsQuestion->mode === AgentDecisionModelSettings::MODE_ON
                    ? AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL
                    : AgentPlanDynamicsDecision::DECIDED_BY_SHADOW;
            }
        }
        $decision->decidedBy = $decidedBy;
        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        // the plan standing NOW, before any call of this turn — a plan the model creates later in the turn is no earlier plan
        $decision->recordPlanStandingAtDecision($agentWorkingState->taskPlan);
        $agentWorkingState->planDynamicsDecision = $decision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'planDynamicsDecision');
        $outcome = $decision->buildStartOutcome($planDynamicsQuestion->mode, $agentWorkingState->countFinishedTaskPlans(), $agentWorkingState->taskPlan);
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        $agentDecisionCallsService->recordDecision($conversation, $decision->buildRecord(
            AgentDecisionModelSettings::SITE_PLAN_DYNAMICS,
            $decision->isApplied(),
            $elapsedMs,
            $transportFailureReason,
            $argusDecision?->stateForSite(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS),
            $argusDecision?->answersForSite(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS),
            $outcome,
            $argusDecision?->questionsForSite(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS)
        ));
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        $aIConversationTurnEventsService->recordEventOfConversation(
            $conversation,
            AIConversationTurnEvent::KIND_DECISION,
            AgentDecisionModelSettings::SITE_PLAN_DYNAMICS,
            $initiatorMessageIndex,
            null,
            null,
            $outcome + ['decidedBy' => $decision->decidedBy, 'transportFailureReason' => $transportFailureReason],
            $runToken,
            $elapsedMs
        );
        $this->aiTrace('turn.planDynamics.decided', ['conversationId' => $conversation->id ?? null, 'decidedBy' => $decision->decidedBy] + $outcome);
    }
}
