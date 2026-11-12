<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartBatchResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSiteInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartDecisionsInFlight;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadTurnInputs;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The folded turn-start request: ONE settled, timed batch for
 * every eligible site of the turn start — the primary request carrying the reply-language, skill-preload,
 * reasoning-effort, plan-dynamics and direct-widget questions, one child per loaded skill's sub-skill pass and the owner-message
 * injection check — run through the decision-call runner and classified ONCE for all sites. It never applies an answer:
 * the loop hands the result to each site in the persisted order.
 */
class AgentTurnStartDecisionBatchService extends Service
{
    use AITraceLogTrait;

    /** The batch key of the primary request. */
    public const string PRIMARY_REQUEST_KEY = 'turnStart';

    /**
     * The turn-start decisions of the current owner turn, before step 1: the sites'
     * questions in their order (the skill-preload roots pass, the sub-skill passes of the skills loaded before the turn,
     * the reasoning effort — applied here already when its precondition decides —, the plan dynamics, the reply language,
     * the owner-message injection check), ONE batch for all of them ({@see runBatch()}), the application in the persisted
     * order and the request events. Every catalog build of the sites reads `$agentRun`'s per-run memos.
     *
     * The reply language — decided once per owner message.
     *
     * The decision model ({@see ArgusAgentTurnStartDecision}, TypeSafe Jev via OpenRouter's System One route)
     * answers three NARROW typed questions over the owner's own recent words (+ the rendered standing-directive block):
     * the language the owner WRITES in, the language the owner explicitly ASKS for, and the language the DIRECTIVES
     * prescribe. The precedence is resolved HERE in PHP — RECENCY: request-now → written-now →
     * the younger of {the conversation's anchor, the newest directive} by instant → the configured language
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision::resolveByRecency()}). The outcome is stamped on the working state
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$replyLanguageDecision}, its own JSON slice) and read by
     * {@see AIConversation::getAppliedReplyLanguageDecision()} on every context build of this turn.
     *
     * Gates and guarantees:
     * - the config's `mode: off` / the site not `on` or `shadow` in its `sites` → returns before anything else: no HTTP
     *   call, no stamp, the cue bytes are exactly today's. `shadow` calls + stamps but the stamp is never applied.
     * - No owner words (turn 1, a delegation child, a signal-started run) → no call: the configured chain decides.
     * - A chunk resume of the same owner message → the existing stamp stands (idempotent).
     * - Outages: the shared circuit breaker is checked FIRST (open → a fallback stamp, no HTTP); a
     *   transport failure or timeout trips it for the config's `breakerSeconds`; a budget / envelope / scope refusal is
     *   NOT a transport failure and never trips it — every site is stamped `fallback:refused`
     *   Only a parsable answer closes the breaker. Every path is best-effort: a failure here can
     *   never wedge the turn.
     */
    public function decideTurnStart(AgentRun $agentRun, AIConversation $conversation, ?string $runToken = null): void
    {
        $this->applyTurnStartDecisions($this->sendTurnStartDecisions($agentRun, $conversation, $runToken));
    }

    /**
     * The first half of {@see decideTurnStart()}: build every site's question and SEND the one folded batch, without
     * waiting for its answers — the loop does its own work (the step budget, the mandate sweep) while they travel and
     * then hands the result to {@see applyTurnStartDecisions()}. The reasoning effort decided structurally is applied
     * here, as before the batch. Null when nothing is asked or the inputs failed (fail-open, as the whole site does).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function sendTurnStartDecisions(AgentRun $agentRun, AIConversation $conversation, ?string $runToken = null): ?AgentTurnStartDecisionsInFlight
    {
        // the reply-language site and the skill-preload site share ONE turn-start request (one call, one
        // booking under the language prompt); each site is eligible on its own, contributes its own questions, parses
        // its own answers and keeps its own completion marker. The language site's behaviour is unchanged.
        try {
            $decisionModelSettings = AgentDecisionModelSettings::current();
            // what the roots question and every sub-skill question read alike (the catalog, the entitlement, the loaded
            // skills' tool summaries, the thread), read ONCE for all of them
            $skillPreloadTurnInputs = $this->buildSkillPreloadTurnInputsWithoutThrowing($conversation, $decisionModelSettings, $agentRun);
            $skillPreloadQuestion = $this->skillPreloadDecisionsService()->buildSkillPreloadRequestInput($conversation, $decisionModelSettings, agentRun: $agentRun, skillPreloadTurnInputs: $skillPreloadTurnInputs);
            // The sub-skills of skills that are ALREADY loaded: one request each, children of the same batch (parallel).
            $loadedSkillSubSkillQuestions = $this->skillPreloadDecisionsService()->buildLoadedSkillSubSkillPreloadRequestInputs($conversation, $decisionModelSettings, $agentRun, $skillPreloadTurnInputs);
            // the effort site — PHP's precondition first; only an admitted turn folds its questions in
            $reasoningEffortQuestion = $this->reasoningEffortDecisionsService()->buildReasoningEffortRequestInput($conversation, $decisionModelSettings);
            if ($reasoningEffortQuestion !== null && !$reasoningEffortQuestion->askDecisionModel) {
                $this->reasoningEffortDecisionsService()->applyReasoningEffortDecision($conversation, $reasoningEffortQuestion, null, null, $decisionModelSettings, $runToken, null, null);
                $reasoningEffortQuestion = null; // decided structurally — not part of the request
            }
            // a delegation never reaches the purpose questions there — not asked here either
            $conversationWorksAutonomously = !$conversation->isDelegation() && $this->purposeRunsService()->conversationPurposeWorksAutonomously($conversation);
            $conversationSeedsATaskPlan = !$conversation->isDelegation() && $this->purposeRunsService()->conversationPurposeSeedsItsTaskPlan($conversation);
            // the plan the previous owner turn finished reopens for the owner's very next message — first,
            // so the plan question below finds the turn decided
            $this->planDynamicsDecisionsService()->reopenFinishedTaskPlanOfThePreviousOwnerTurnIfDue($conversation, $decisionModelSettings, $conversationWorksAutonomously, $conversationSeedsATaskPlan, $runToken);
            // the plan-dynamics site — asked only when no plan with open items is active (the rule:
            // while a plan with remaining items is active, the decision model is not asked)
            $planDynamicsQuestion = $this->planDynamicsDecisionsService()->buildRequestInput(
                $conversation,
                $decisionModelSettings,
                $conversationWorksAutonomously,
                $conversationSeedsATaskPlan
            );
            $siteInputs = new AgentTurnStartSiteInputs();
            $siteInputs->skillPreloadQuestion = $skillPreloadQuestion;
            $siteInputs->loadedSkillSubSkillQuestions = $loadedSkillSubSkillQuestions;
            $siteInputs->reasoningEffortQuestion = $reasoningEffortQuestion;
            $siteInputs->planDynamicsQuestion = $planDynamicsQuestion;
            // the turn's direct widget — applied after the preload, which it defers to
            $siteInputs->directWidgetQuestion = $this->directWidgetDecisionsService()->buildRequestInput($conversation, $decisionModelSettings);
            $siteInputs->replyLanguageQuestion = $this->replyLanguageDecisionsService()->buildRequestInput($conversation, $decisionModelSettings);
            // the turn-start line site: whether the activity stack opens with a line on what the assistant does now
            $siteInputs->turnStartLineQuestion = $this->turnStartLineDecisionsService()->buildRequestInput($conversation, $decisionModelSettings);
            $siteInputs->ownerMessageIndex = $conversation->getNewestUserMessage()?->indexInConversation ?? null;
            // the injection check of the current owner message — one more parallel child of this batch
            $siteInputs->ownerMessageForInjectionCheck = $this->ownerMessageScreeningService()->findOwnerMessageForInjectionCheck($conversation, $decisionModelSettings);
            $agentRun->stampPreFirstStepPart('decisionInputs');
            if ($siteInputs->asksNothing()) {
                return null;
            }

            // ONE request for every eligible site, classified once for all of them
            $agentTurnStartDecisionsInFlight = $this->sendBatch($conversation, $siteInputs, $decisionModelSettings);
            $agentTurnStartDecisionsInFlight->agentRun = $agentRun;
            $agentTurnStartDecisionsInFlight->runToken = $runToken;
            $agentRun->stampPreFirstStepPart('decisionSend');
            return $agentTurnStartDecisionsInFlight;
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $decisionError) {
            $this->aiTrace('turn.replyLanguage.failed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionError->getMessage()]);
            return null;
        }
    }

    /** Move the requests of {@see sendTurnStartDecisions()} forward between the loop's own steps (never waits, never throws). */
    public function advanceTurnStartDecisions(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        try {
            $agentTurnStartDecisionsInFlight?->decisionBatch?->advanceDecisionRequests();
        } catch (Throwable) {
            // a transfer error surfaces when the answers are received
        }
    }

    /**
     * Receive the answers of {@see sendTurnStartDecisions()} when {@see applyTurnStartDecisions()} never did — the loop's
     * work between the send and the application threw or returned. The provider bills the call either way: the receive
     * books its usage and closes its transfers, so nothing stays open into the next job. Nothing is applied, nothing
     * is classified; never throws.
     */
    public function receiveTurnStartDecisionsNotApplied(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        if ($agentTurnStartDecisionsInFlight === null || $agentTurnStartDecisionsInFlight->received) {
            return;
        }
        $agentTurnStartDecisionsInFlight->received = true;
        $decisionBatchInFlight = $agentTurnStartDecisionsInFlight->decisionBatchInFlight;
        if ($decisionBatchInFlight === null || $decisionBatchInFlight->sendError !== null) {
            return;
        }
        try {
            $decisionBatchInFlight->decisionBatch->receiveDecisionResponses();
        } catch (Throwable $receiveError) {
            $this->aiTrace('turn.decisions.notAppliedReceiveFailed', ['conversationId' => $agentTurnStartDecisionsInFlight->conversation->id ?? null, 'message' => $receiveError->getMessage()]);
        }
    }

    /**
     * The second half of {@see decideTurnStart()}: wait for the answers of {@see sendTurnStartDecisions()} and apply them
     * in the persisted order. The working-state slices the sites stamp land in ONE write at the end, not one per site
     * and decision record, and the turn events the sites and the requests record follow it
     * ({@see writeCollectedSlicesThenEvents()}). Nothing when nothing was sent.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function applyTurnStartDecisions(?AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): void
    {
        if ($agentTurnStartDecisionsInFlight === null) {
            return;
        }
        $agentRun = $agentTurnStartDecisionsInFlight->agentRun;
        $conversation = $agentTurnStartDecisionsInFlight->conversation;
        $runToken = $agentTurnStartDecisionsInFlight->runToken;
        $decisionModelSettings = $agentTurnStartDecisionsInFlight->decisionModelSettings;
        $siteInputs = $agentTurnStartDecisionsInFlight->siteInputs;
        $skillPreloadQuestion = $siteInputs->skillPreloadQuestion;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->collectAgentWorkingStateSliceWrites($conversation);
        $this->turnEventsService()->collectEventsOfConversation($conversation);
        $writesCollectedSlices = true;
        $turnStartLineAnnounced = false;
        try {
            $batchResult = $this->receiveBatch($agentTurnStartDecisionsInFlight);
            $agentRun->stampPreFirstStepPart('decisionWait');
            $decidedBy = $batchResult->decidedBy;
            $primaryDecision = $batchResult->primaryDecision;
            $elapsedMs = $batchResult->elapsedMs;
            $transportFailureReason = $batchResult->transportFailureReason;

            // The sites apply in the persisted order: owner-message injection, reply language, preload, reasoning
            // effort, plan dynamics, the direct widget, the turn-start line, then per sub-skill its application followed by its event, then the
            // request event.
            if ($siteInputs->ownerMessageForInjectionCheck !== null) {
                $this->ownerMessageScreeningService()->applyDecision($conversation, $siteInputs->ownerMessageForInjectionCheck, $batchResult->ownerMessageInjectionDecision, $decisionModelSettings, $elapsedMs, $batchResult->refused);
            }
            $requestId = bin2hex(random_bytes(8));
            if ($siteInputs->replyLanguageQuestion !== null) {
                $this->replyLanguageDecisionsService()->applyDecision($conversation, $siteInputs->replyLanguageQuestion, $primaryDecision, $decidedBy, $decisionModelSettings, $elapsedMs, $transportFailureReason, $batchResult->rawResponseExcerpt);
            }
            if ($skillPreloadQuestion !== null) {
                $this->skillPreloadDecisionsService()->applySkillPreloadDecision($conversation, $skillPreloadQuestion, $primaryDecision, $decidedBy, $decisionModelSettings, $runToken, $requestId, $elapsedMs, $transportFailureReason, $agentRun);
            }
            if ($siteInputs->reasoningEffortQuestion !== null) {
                $this->reasoningEffortDecisionsService()->applyReasoningEffortDecision($conversation, $siteInputs->reasoningEffortQuestion, $primaryDecision, $decidedBy, $decisionModelSettings, $runToken, $elapsedMs, $transportFailureReason);
            }
            if ($siteInputs->planDynamicsQuestion !== null) {
                $this->planDynamicsDecisionsService()->applyDecision($conversation, $siteInputs->planDynamicsQuestion, $primaryDecision, $decidedBy, $runToken, $elapsedMs, $transportFailureReason);
            }
            if ($siteInputs->directWidgetQuestion !== null) {
                $this->directWidgetDecisionsService()->applyDecision($conversation, $siteInputs->directWidgetQuestion, $primaryDecision, $decidedBy, $runToken, $elapsedMs, $transportFailureReason);
            }
            if ($siteInputs->turnStartLineQuestion !== null) {
                $turnStartLineAnnounced = $this->turnStartLineDecisionsService()->applyDecision($conversation, $siteInputs->turnStartLineQuestion, $primaryDecision, $decidedBy, $runToken, $elapsedMs, $transportFailureReason);
            }
            // the sub-skills of the skills loaded BEFORE this turn: each child parses its answer and admits on its own
            foreach ($siteInputs->loadedSkillSubSkillQuestions->getElements() as $turnStartSubSkillQuestion) {
                $loadedSkillName = $turnStartSubSkillQuestion->loadedSkillName;
                $loadedSkillSubSkillQuestion = $turnStartSubSkillQuestion->skillPreloadQuestion;
                $subSkillDecision = $batchResult->subSkillDecisions->getDecisionOfLoadedSkill($loadedSkillName);
                $subSkillDecidedBy = $batchResult->getSubSkillDecidedBy($loadedSkillName);
                $subSkillFailureReason = $batchResult->getSubSkillFailureReason($loadedSkillName);
                $subSkillRequestId = bin2hex(random_bytes(8));
                try {
                    $this->skillPreloadDecisionsService()->applySkillPreloadDecision($conversation, $loadedSkillSubSkillQuestion, $subSkillDecision, $subSkillDecidedBy, $decisionModelSettings, $runToken, $subSkillRequestId, $elapsedMs, $subSkillFailureReason, $agentRun);
                } catch (AgentRunClaimLostException $claimLost) {
                    throw $claimLost;
                } catch (Throwable $subSkillError) {
                    $this->aiTrace('turn.skillPreload.loadedSkillFailed', ['conversationId' => $conversation->id ?? null, 'loadedSkillName' => $loadedSkillName, 'message' => $subSkillError->getMessage()]);
                    continue;
                }
                $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_REQUEST, self::PRIMARY_REQUEST_KEY, $loadedSkillSubSkillQuestion->initiatorMessageIndex, null, $subSkillRequestId, [
                    'sites' => ['skillPreload'],
                    'batchId' => $batchResult->batchId,
                    'pass' => $loadedSkillSubSkillQuestion->pass,
                    'unlockedByRootSkillName' => $loadedSkillSubSkillQuestion->unlockedByRootSkillName,
                    'subSkillPassSubject' => AgentSkillPreloadDecisionsService::SKILL_PRELOAD_SUBJECT_LOADED_SKILL,
                    'decidedBy' => $subSkillDecidedBy ?? 'answered',
                    'transportFailureReason' => $subSkillFailureReason,
                    'modelVersion' => $subSkillDecision?->modelVersion,
                ], $runToken, $elapsedMs, $loadedSkillSubSkillQuestion->arm, $subSkillRequestId);
            }
            // The REQUEST's own cost/latency row, in every arm (the control arm has no B decision row).
            if ($siteInputs->primaryRequestCarriesASite()) {
                $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_REQUEST, self::PRIMARY_REQUEST_KEY, $skillPreloadQuestion?->initiatorMessageIndex ?? $siteInputs->ownerMessageIndex, null, $requestId, $batchResult->buildRequestEventPayload($siteInputs), $runToken, $elapsedMs, $skillPreloadQuestion?->arm, $requestId);
            }
            $agentRun->stampPreFirstStepPart('decisionApply');
        } catch (AgentRunClaimLostException $claimLost) {
            $writesCollectedSlices = false;
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $decisionError) {
            $this->aiTrace('turn.replyLanguage.failed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionError->getMessage()]);
        } finally {
            $this->writeCollectedSlicesThenEvents($conversation, $writesCollectedSlices);
        }
        // after the slice is written: the client's next activity read finds the line
        if ($turnStartLineAnnounced) {
            $this->turnStartLineDecisionsService()->publishTurnStartLineSignal($conversation);
        }
    }

    /**
     * The end of the collected stretch of {@see applyTurnStartDecisions()}: the working-state slices first, in ONE
     * write, then the turn events the sites recorded meanwhile — an "already decided" check reads the slice, so no
     * event may land without it. A lost claim drops both (the new owner decides again); any other failure of the slice
     * write is traced and the turn goes on (the decisions stand in memory for this run), the events are recorded.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the slices were written
     */
    protected function writeCollectedSlicesThenEvents(AIConversation $conversation, bool $claimHeld): void
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationTurnEventsService = $this->turnEventsService();
        try {
            $aIConversationsService->writeCollectedAgentWorkingStateSlices($conversation, $claimHeld);
        } catch (AgentRunClaimLostException $claimLost) {
            $aIConversationTurnEventsService->recordCollectedEventsOfConversation($conversation, false);
            throw $claimLost;
        } catch (Throwable $sliceWriteError) {
            $this->aiTrace('turn.decisions.sliceWriteFailed', ['conversationId' => $conversation->id ?? null, 'message' => $sliceWriteError->getMessage()]);
        }
        $aIConversationTurnEventsService->recordCollectedEventsOfConversation($conversation, $claimHeld);
    }

    /**
     * Run the batch: {@see sendBatch()} and {@see receiveBatch()} at once.
     */
    public function runBatch(
        AIConversation $conversation,
        AgentTurnStartSiteInputs $siteInputs,
        AgentDecisionModelSettings $decisionModelSettings
    ): AgentTurnStartBatchResult {
        return $this->receiveBatch($this->sendBatch($conversation, $siteInputs, $decisionModelSettings));
    }

    /**
     * Send the batch. The primary request is filled from the sites' questions first; the children the site services own
     * (one per loaded skill's sub-skill pass, the injection check) are built only when the breaker is closed — an open
     * breaker builds and sends no request ({@see buildDecisionBatch()}).
     */
    protected function sendBatch(
        AIConversation $conversation,
        AgentTurnStartSiteInputs $siteInputs,
        AgentDecisionModelSettings $decisionModelSettings
    ): AgentTurnStartDecisionsInFlight {
        $agentTurnStartDecisionsInFlight = new AgentTurnStartDecisionsInFlight();
        $agentTurnStartDecisionsInFlight->conversation = $conversation;
        $agentTurnStartDecisionsInFlight->siteInputs = $siteInputs;
        $agentTurnStartDecisionsInFlight->decisionModelSettings = $decisionModelSettings;
        $agentTurnStartDecisionsInFlight->batchResult = new AgentTurnStartBatchResult();
        $primaryDecision = new ArgusAgentTurnStartDecision();
        $agentTurnStartDecisionsInFlight->primaryDecision = $primaryDecision;
        // the primary request is filled BEFORE the call: a failure here is not the call's own error (no site is stamped)
        $replyLanguageQuestion = $siteInputs->replyLanguageQuestion;
        if ($replyLanguageQuestion !== null) {
            // the conversational context of the written question — built here, independent of the
            // preload site, so a purposed run without a preload gets it too ("online booking" after a German
            // offer is not a switch)
            $primaryDecision->fillReplyLanguageQuestions(
                $replyLanguageQuestion,
                $conversation->messages->buildOwnerTurns()->buildRecentTurnTextsBefore($replyLanguageQuestion->ownerMessageIndex)
            );
        }
        if ($siteInputs->skillPreloadQuestion !== null) {
            $primaryDecision->fillSkillPreloadQuestions($siteInputs->skillPreloadQuestion);
        }
        if ($siteInputs->reasoningEffortQuestion !== null) {
            $primaryDecision->fillReasoningEffortQuestions($siteInputs->reasoningEffortQuestion);
        }
        if ($siteInputs->planDynamicsQuestion !== null) {
            $primaryDecision->fillPlanDynamicsQuestions($siteInputs->planDynamicsQuestion);
        }
        if ($siteInputs->directWidgetQuestion !== null) {
            $primaryDecision->fillDirectWidgetQuestions($siteInputs->directWidgetQuestion);
        }
        if ($siteInputs->turnStartLineQuestion !== null) {
            $primaryDecision->fillTurnStartLineQuestions($siteInputs->turnStartLineQuestion);
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $primaryDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
        $primaryDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
        $primaryDecision->setTimeoutSeconds($decisionModelSettings->timeoutSeconds);
        $agentDecisionCallsService = $this->decisionCallsService();
        if (!$agentDecisionCallsService->isCircuitBreakerOpen()) {
            $decisionBatch = $this->buildDecisionBatch($conversation, $siteInputs, $decisionModelSettings, $primaryDecision, $agentTurnStartDecisionsInFlight->batchResult);
            $agentTurnStartDecisionsInFlight->decisionBatch = $decisionBatch;
            $agentTurnStartDecisionsInFlight->decisionBatchInFlight = $agentDecisionCallsService->sendDecisionBatchWithoutBreakerCheck(
                $decisionBatch,
                $siteInputs->primaryRequestCarriesASite() ? $primaryDecision : null,
                $decisionModelSettings->timeoutSeconds,
                $decisionModelSettings->breakerSeconds,
                'turn-start'
            );
        }
        return $agentTurnStartDecisionsInFlight;
    }

    /** Receive the batch {@see sendBatch()} sent and classify it ONCE for every site. */
    protected function receiveBatch(AgentTurnStartDecisionsInFlight $agentTurnStartDecisionsInFlight): AgentTurnStartBatchResult
    {
        $conversation = $agentTurnStartDecisionsInFlight->conversation;
        $siteInputs = $agentTurnStartDecisionsInFlight->siteInputs;
        $result = $agentTurnStartDecisionsInFlight->batchResult;
        $agentDecisionCallsService = $this->decisionCallsService();
        $agentTurnStartDecisionsInFlight->received = true;
        $decisionCallOutcome = $agentTurnStartDecisionsInFlight->decisionBatchInFlight !== null
            ? $agentDecisionCallsService->receiveDecisionBatch($agentTurnStartDecisionsInFlight->decisionBatchInFlight)
            : AgentDecisionCallOutcome::build(AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN);
        $result->transportFailureReason = $decisionCallOutcome->failureReason;
        $result->elapsedMs = $decisionCallOutcome->elapsedMs;
        $result->batchId = $agentTurnStartDecisionsInFlight->decisionBatch?->batchId;
        $result->primaryDecision = $agentTurnStartDecisionsInFlight->primaryDecision;
        if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_REFUSED) {
            // Our own gate said no — not an outage, never a breaker trip; every site is stamped `fallback:refused`
            $this->aiTrace('turn.replyLanguage.skipped', ['conversationId' => $conversation->id ?? null, 'reason' => $result->transportFailureReason]);
            $result->refused = true;
        }
        // breaker open / refused / transport / OUR side (a type error, a missing registry entry or key, a failed estimate —
        // logged by the runner, no trip: the breaker would hide a misconfiguration); null = answered, each site reads its own.
        // The folded request answers for its own sites: a sub-skill sibling that failed at the transport level never
        // voids the language, effort or plan answer that arrived (the runner tripped the breaker on it).
        $result->decidedBy = $decisionCallOutcome->resolveFallbackStamp();
        if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN) {
            $result->primaryDecision = null;
        }
        // only a parsable answer of a breaker-relevant child closes it: the owner-message injection check never does
        $agentDecisionCallsService->closeCircuitBreakerAfter($decisionCallOutcome, $result->hasAParsableBreakerRelevantAnswer($siteInputs));
        if ($result->primaryDecision !== null && $decisionCallOutcome->childrenSettled) {
            $rawResponse = $result->primaryDecision->lastRawResponse ?? null;
            $result->rawResponseExcerpt = $rawResponse !== null ? mb_substr((string)json_encode($rawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400) : null;
        }
        return $result;
    }

    /**
     * The batch of the turn start: the primary request when it carries a site, then one child per loaded skill's
     * sub-skill pass and the injection check — the children built by their site services, in that order, and kept on
     * `$result` for the application.
     */
    protected function buildDecisionBatch(
        AIConversation $conversation,
        AgentTurnStartSiteInputs $siteInputs,
        AgentDecisionModelSettings $decisionModelSettings,
        ArgusAgentTurnStartDecision $primaryDecision,
        AgentTurnStartBatchResult $result
    ): ArgusAgentDecisions {
        $decisionBatch = new ArgusAgentDecisions();
        if ($siteInputs->primaryRequestCarriesASite()) {
            $decisionBatch->addChild($primaryDecision, self::PRIMARY_REQUEST_KEY);
        }
        foreach ($siteInputs->loadedSkillSubSkillQuestions->getElements() as $turnStartSubSkillQuestion) {
            $result->subSkillDecisions->addDecisionOfLoadedSkill(
                $turnStartSubSkillQuestion->loadedSkillName,
                $this->skillPreloadDecisionsService()->buildSubSkillPreloadDecisionRequest($conversation, $turnStartSubSkillQuestion->skillPreloadQuestion, $decisionModelSettings)
            );
        }
        if ($siteInputs->ownerMessageForInjectionCheck !== null) {
            $result->ownerMessageInjectionDecision = $this->ownerMessageScreeningService()->buildInjectionCheckRequest($conversation, $siteInputs->ownerMessageForInjectionCheck);
        }
        foreach ($result->subSkillDecisions->getElements() as $turnStartSubSkillDecision) {
            $decisionBatch->addChild(
                $turnStartSubSkillDecision->turnStartDecision,
                self::PRIMARY_REQUEST_KEY . ':' . AgentSkillPreloadDecisionsService::SKILL_PRELOAD_SUBJECT_LOADED_SKILL . ':' . $turnStartSubSkillDecision->loadedSkillName
            );
        }
        if ($result->ownerMessageInjectionDecision !== null) {
            $decisionBatch->addChild($result->ownerMessageInjectionDecision, self::PRIMARY_REQUEST_KEY . ':ownerMessageInjection');
        }
        return $decisionBatch;
    }

    /** The turn start's shared skill-preload inputs; null when the site does not run or a read failed (each question then builds its own and fails open). */
    protected function buildSkillPreloadTurnInputsWithoutThrowing(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, AgentRun $agentRun): ?AgentSkillPreloadTurnInputs
    {
        try {
            return $this->skillPreloadDecisionsService()->buildSkillPreloadTurnInputs($conversation, $decisionModelSettings, [], $agentRun);
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.skillPreload.turnInputsFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }

    protected function skillPreloadDecisionsService(): AgentSkillPreloadDecisionsService
    {
        /** @var AgentSkillPreloadDecisionsService $agentSkillPreloadDecisionsService */
        $agentSkillPreloadDecisionsService = DDDService::instance()->getService(AgentSkillPreloadDecisionsService::class);
        return $agentSkillPreloadDecisionsService;
    }

    protected function ownerMessageScreeningService(): AIConversationOwnerMessageScreeningService
    {
        /** @var AIConversationOwnerMessageScreeningService $aIConversationOwnerMessageScreeningService */
        $aIConversationOwnerMessageScreeningService = DDDService::instance()->getService(AIConversationOwnerMessageScreeningService::class);
        return $aIConversationOwnerMessageScreeningService;
    }

    protected function replyLanguageDecisionsService(): AgentReplyLanguageDecisionsService
    {
        /** @var AgentReplyLanguageDecisionsService $agentReplyLanguageDecisionsService */
        $agentReplyLanguageDecisionsService = DDDService::instance()->getService(AgentReplyLanguageDecisionsService::class);
        return $agentReplyLanguageDecisionsService;
    }

    protected function reasoningEffortDecisionsService(): AgentReasoningEffortDecisionsService
    {
        /** @var AgentReasoningEffortDecisionsService $agentReasoningEffortDecisionsService */
        $agentReasoningEffortDecisionsService = DDDService::instance()->getService(AgentReasoningEffortDecisionsService::class);
        return $agentReasoningEffortDecisionsService;
    }

    protected function planDynamicsDecisionsService(): AgentPlanDynamicsDecisionsService
    {
        /** @var AgentPlanDynamicsDecisionsService $agentPlanDynamicsDecisionsService */
        $agentPlanDynamicsDecisionsService = DDDService::instance()->getService(AgentPlanDynamicsDecisionsService::class);
        return $agentPlanDynamicsDecisionsService;
    }

    protected function directWidgetDecisionsService(): AgentDirectWidgetDecisionsService
    {
        /** @var AgentDirectWidgetDecisionsService $agentDirectWidgetDecisionsService */
        $agentDirectWidgetDecisionsService = DDDService::instance()->getService(AgentDirectWidgetDecisionsService::class);
        return $agentDirectWidgetDecisionsService;
    }

    protected function turnStartLineDecisionsService(): AgentTurnStartLineDecisionsService
    {
        /** @var AgentTurnStartLineDecisionsService $agentTurnStartLineDecisionsService */
        $agentTurnStartLineDecisionsService = DDDService::instance()->getService(AgentTurnStartLineDecisionsService::class);
        return $agentTurnStartLineDecisionsService;
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return $aIConversationPurposeRunsService;
    }

    protected function turnEventsService(): AIConversationTurnEventsService
    {
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        return $aIConversationTurnEventsService;
    }
}
