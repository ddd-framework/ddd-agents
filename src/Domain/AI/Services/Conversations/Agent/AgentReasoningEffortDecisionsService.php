<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortCatalogEfforts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortFloor;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortLimits;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolProposesIdentityOrClass;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The reasoning-effort decision site (layer L2): the PHP precondition and the request
 * input of the folded turn-start question, the application of its answer (the working-state slice the model call
 * reads), and the effort each model call is sent with — the catalog's configured effort and ceiling, a fixed effort,
 * the purpose's base and floor, the row's pin and the next plan item's minimum. Those are gathered once per read into
 * an {@see AgentReasoningEffortLimits} ({@see buildReasoningEffortLimits()}), whose arithmetic answers every effort
 * question.
 */
class AgentReasoningEffortDecisionsService extends Service
{
    use AITraceLogTrait;

    /**
     * The reasoning-effort site's request input, or null when the site does not run for this turn:
     * site `off` (the env veto, the kill switch, the row `off`), a delegation, a signal-started turn, a chunk resume
     * of an already-decided owner turn. Otherwise the PHP PRECONDITION is judged here — the word floor, an
     * identity/class proposal in the previous assistant turn, a stored disagreement, a customer-visible tool failure
     * in the previous turn — and `askDecisionModel` says whether the questions ride the turn-start request at all.
     * Nothing here reads anything the loop does not already hold (no speculative reads).
     */
    public function buildReasoningEffortRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentReasoningEffortDecisionQuestion
    {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_REASONING_EFFORT, $conversation);
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || !isset($conversation->id)) {
                return null;
            }
            if (AgentReasoningEffortLimits::readFixedEffort($conversation) !== null) {
                return null; // a fixed effort is sent whatever the site would answer — the question is not asked
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            if ($initiatorMessageIndex === null) {
                return null;
            }
            $previousDecision = $conversation->getAgentWorkingState()?->reasoningEffortDecision;
            if ($previousDecision !== null && $previousDecision->ownerMessageIndex === $initiatorMessageIndex) {
                return null; // a chunk resume of the same owner turn — the decided effort stands
            }
            $initiatorMessage = null;
            $previousInitiatorMessageIndex = null;
            foreach ($conversation->messages->getElements() as $message) {
                $messageIndex = $message->indexInConversation ?? null;
                if ($messageIndex === $initiatorMessageIndex) {
                    $initiatorMessage = $message;
                } elseif ($messageIndex !== null && $messageIndex < $initiatorMessageIndex && AIConversationMessages::isTurnInitiatorMessage($message)) {
                    $previousInitiatorMessageIndex = $messageIndex;
                }
            }
            if (!AIConversationMessages::initiatorCarriesOwnerWords($initiatorMessage)) {
                return null; // a signal-started turn, a widget click, a delegation seed — not the owner's words (a submitted form is)
            }
            // The previous assistant turn: the messages between the previous initiator and this one.
            $assistantTextBefore = '';
            $previousTurnProposedIdentityOrClass = false;
            $previousTurnFailedVisibly = false;
            foreach ($conversation->messages->getElements() as $message) {
                $messageIndex = $message->indexInConversation ?? PHP_INT_MAX;
                if ($messageIndex >= $initiatorMessageIndex || ($previousInitiatorMessageIndex !== null && $messageIndex < $previousInitiatorMessageIndex)) {
                    continue;
                }
                if ($message->isAssistantAuthoredText()
                    && trim((string)($message->message ?? '')) !== ''
                ) {
                    $assistantTextBefore = (string)$message->message;
                    // a widget that proposes an identity or a class ({@see ConversationWidget::PROPOSES_IDENTITY_OR_CLASS})
                    // makes the owner's reply an identity reply, like the tool attribute below
                    if (ConversationWidgetClasses::isOneOfWidgetTypesProposingIdentityOrClass($message->getWidgetTypes())) {
                        $previousTurnProposedIdentityOrClass = true;
                    }
                }
                if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL && isset($message->toolCall)) {
                    // an identity / classification tool ({@see MCPToolProposesIdentityOrClass}) in the PREVIOUS assistant turn makes
                    // the owner's reply an identity reply: a short "no, that is not me" is admitted only then
                    if (MCPToolAttributeCatalog::hasAttributeOnToolName((string)($message->toolCall->name ?? ''), MCPToolProposesIdentityOrClass::class)) {
                        $previousTurnProposedIdentityOrClass = true;
                    }
                    // customer-visible = the tool RAN and failed (never the loop's own refusals or gates)
                    if (($message->toolCall->status ?? null) === AIConversationMessage::STATUS_FAILED
                        && ($message->toolCall->executionStartedAt ?? null) !== null
                        && !$message->toolCall->wasRefusedAsDuplicate
                        && !$message->toolCall->wasRefusedByGate
                    ) {
                        $previousTurnFailedVisibly = true;
                    }
                }
            }
            // The stored facts PHP already holds (the loaded space — never a lazy load) and the last identity
            // resolution result already in the thread.
            $storedFactLines = $conversation->buildStoredFactLinesForDecisions();
            foreach (array_reverse($conversation->messages->getElements()) as $message) {
                if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL
                    && isset($message->toolCall)
                    && MCPToolAttributeCatalog::getAttributeOfToolName((string)($message->toolCall->name ?? ''), MCPToolProposesIdentityOrClass::class)?->resultIsQuotedAsIdentityResolution === true
                    && trim((string)($message->toolCall->result ?? '')) !== ''
                ) {
                    $storedFactLines[] = 'identity resolution: ' . mb_substr(trim((string)$message->toolCall->result), 0, 200);
                    break;
                }
            }
            $storedFacts = implode("\n", $storedFactLines);
            $disagreement = $this->resolveStoredDisagreementForReasoningEffort($conversation) ?? '';
            $openWorkItems = $conversation->getTaskPlan()?->getOpenItemPathTexts() ?? [];
            $preconditionReason = AgentReasoningEffortDecision::resolvePreconditionReason(
                (string)$initiatorMessage->message,
                $previousTurnProposedIdentityOrClass,
                $disagreement !== '',
                $previousTurnFailedVisibly
            );
            // The ACTIVE effort of the turn: the decision model is told what the assistant already
            // reasons at and judges only raises above it; at the ceiling there is nothing to decide.
            $agentReasoningEffortFloor = $this->resolveFloorReasoningEffortForCurrentTurn($conversation, $decisionModelSettings);
            if ($preconditionReason !== AgentReasoningEffortDecision::PRECONDITION_NONE
                && AgentDecisionModelSettings::reasoningEffortRank($agentReasoningEffortFloor->effort) >= AgentDecisionModelSettings::reasoningEffortRank($agentReasoningEffortFloor->ceilingEffort)
            ) {
                $preconditionReason = AgentReasoningEffortDecision::PRECONDITION_AT_CEILING;
            }
            $reasoningEffortQuestion = new AgentReasoningEffortDecisionQuestion();
            $reasoningEffortQuestion->mode = $siteMode;
            $reasoningEffortQuestion->initiatorMessageIndex = $initiatorMessageIndex;
            $reasoningEffortQuestion->askDecisionModel = !in_array($preconditionReason, [AgentReasoningEffortDecision::PRECONDITION_NONE, AgentReasoningEffortDecision::PRECONDITION_AT_CEILING], true);
            $reasoningEffortQuestion->preconditionReason = $preconditionReason;
            $reasoningEffortQuestion->floorEffort = $agentReasoningEffortFloor->effort;
            $reasoningEffortQuestion->floorSource = $agentReasoningEffortFloor->source;
            $reasoningEffortQuestion->ceilingEffort = $agentReasoningEffortFloor->ceilingEffort;
            $reasoningEffortQuestion->ownerMessage = (string)$initiatorMessage->message;
            $reasoningEffortQuestion->assistantTextBefore = $assistantTextBefore;
            $reasoningEffortQuestion->storedFacts = $storedFacts;
            $reasoningEffortQuestion->nextPlanItem = (string)($openWorkItems[0] ?? '');
            $reasoningEffortQuestion->disagreement = $disagreement;
            return $reasoningEffortQuestion;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.reasoningEffort.inputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    /**
     * Two differing STORED values for one field of the record (the input of the weighing question), rendered
     * for the state, or null when PHP holds none. DEFERRED: no source computes a divergency without a read today
     * (deriving it from the stored values is the named follow-up); until it lands the weighing question is never
     * sent and the precondition's disagreement branch never admits a turn.
     */
    protected function resolveStoredDisagreementForReasoningEffort(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * The reasoning-effort site's consequence: type the answers into an {@see AgentReasoningEffortDecision},
     * resolve the effort in PHP (`high` / `medium` / the default), raise to the row's pin, clamp to the ceiling, and
     * stamp the working-state slice the model call reads. Without a request (`$argusDecision === null`, the
     * precondition held nothing) the default effort is stamped as decided by the precondition. Every fallback stamps
     * the CONFIGURED effort so an outage never degrades the turn. Mode `on` applies; `shadow` records only.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function applyReasoningEffortDecision(
        AIConversation $conversation,
        AgentReasoningEffortDecisionQuestion $reasoningEffortQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): void {
        try {
            $applies = $reasoningEffortQuestion->mode === AgentDecisionModelSettings::MODE_ON;
            $decision = new AgentReasoningEffortDecision();
            $decision->ownerMessageIndex = $reasoningEffortQuestion->initiatorMessageIndex;
            $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
            $decision->preconditionReason = $reasoningEffortQuestion->preconditionReason;
            // the site runs for an owner turn only, so whether the conversation works autonomously changes nothing here
            $agentReasoningEffortLimits = $this->buildReasoningEffortLimits($conversation, $decisionModelSettings, false);
            $decision->configuredEffort = $agentReasoningEffortLimits->configuredEffort;
            $decision->ceilingEffort = $agentReasoningEffortLimits->ceilingEffort;
            // the base a call is sent with when nothing raises it: the purpose's own effort, else the catalog's
            $decision->defaultEffort = $agentReasoningEffortLimits->getBaseEffort();
            $decision->pinnedEffort = $agentReasoningEffortLimits->pinnedEffort;
            $decision->floorEffort = $reasoningEffortQuestion->floorEffort ?? $decision->defaultEffort;
            $decision->floorSource = $reasoningEffortQuestion->floorSource ?? AgentReasoningEffortDecision::FLOOR_SOURCE_DEFAULT;
            $decision->modelVersion = $argusDecision?->modelVersion;
            if (!$reasoningEffortQuestion->askDecisionModel) {
                $decision->decidedBy = $applies ? AgentReasoningEffortDecision::DECIDED_BY_PRECONDITION : AgentReasoningEffortDecision::DECIDED_BY_SHADOW;
                $decision->resolvedEffort = null;
            } elseif ($requestDecidedBy !== null) {
                $decision->decidedBy = $requestDecidedBy; // a FALLBACK_* — the request never answered
                $decision->fallbackReason = $requestDecidedBy;
            } elseif ($argusDecision === null || !$argusDecision->effortAnswersUsable()) {
                $decision->decidedBy = AgentReasoningEffortDecision::FALLBACK_UNPARSABLE;
                $decision->fallbackReason = AgentReasoningEffortDecision::FALLBACK_UNPARSABLE;
            } else {
                $decision->disputesWhatTheAssistantClaimsProbability = $argusDecision->disputesWhatTheAssistantClaimsProbability;
                $decision->rejectsIdentityOrClassProbability = $argusDecision->rejectsIdentityOrClassProbability;
                $decision->asksForADifferentCourseProbability = $argusDecision->asksForADifferentCourseProbability;
                $decision->requiresWeighingConflictingRequirementsProbability = $argusDecision->requiresWeighingConflictingRequirementsProbability;
                $decision->resolvedEffort = AgentReasoningEffortDecision::resolveEffortFromAnswers(
                    $decision->disputesWhatTheAssistantClaimsProbability,
                    $decision->rejectsIdentityOrClassProbability,
                    $decision->asksForADifferentCourseProbability,
                    $decision->requiresWeighingConflictingRequirementsProbability,
                    $decision->floorEffort // a raise is judged ABOVE the active effort, never below it
                );
                $decision->decidedBy = $applies ? AgentReasoningEffortDecision::DECIDED_BY_DECISION_MODEL : AgentReasoningEffortDecision::DECIDED_BY_SHADOW;
            }
            $isFallback = $decision->isFallback();
            // the effort the turn is sent with: a fallback = the active floor (the configured value raised by the declared
            // minimums and the pin, never `low`); else max(decided, pin) over the floor, clamped
            $decision->effectiveEffort = $isFallback
                ? $agentReasoningEffortLimits->resolveFallbackEffort($decision->floorEffort)
                : $agentReasoningEffortLimits->resolveAnsweredEffort($decision->resolvedEffort, $decision->floorEffort);

            $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
            $agentWorkingState->reasoningEffortDecision = $decision;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'reasoningEffortDecision');
            $outcome = [
                'applied' => $decision->isApplied(),
                'mode' => $reasoningEffortQuestion->mode,
                'preconditionReason' => $decision->preconditionReason,
                'configuredEffort' => $decision->configuredEffort,
                'defaultEffort' => $decision->defaultEffort,
                'ceilingEffort' => $decision->ceilingEffort,
                'pinnedEffort' => $decision->pinnedEffort,
                'floorEffort' => $decision->floorEffort,
                'floorSource' => $decision->floorSource,
                'resolvedEffort' => $decision->resolvedEffort,
                'effectiveEffort' => $decision->effectiveEffort,
                'fallbackReason' => $decision->fallbackReason,
                'disputesWhatTheAssistantClaimsProbability' => $decision->disputesWhatTheAssistantClaimsProbability,
                'rejectsIdentityOrClassProbability' => $decision->rejectsIdentityOrClassProbability,
                'asksForADifferentCourseProbability' => $decision->asksForADifferentCourseProbability,
                'requiresWeighingConflictingRequirementsProbability' => $decision->requiresWeighingConflictingRequirementsProbability,
            ];
            $this->decisionCallsService()->recordDecision($conversation, $decision->buildRecord(
                AgentDecisionModelSettings::SITE_REASONING_EFFORT,
                $decision->isApplied(),
                $elapsedMs,
                $transportFailureReason,
                $argusDecision?->stateForSite(AgentDecisionModelSettings::SITE_REASONING_EFFORT),
                $argusDecision?->answersForSite(AgentDecisionModelSettings::SITE_REASONING_EFFORT),
                $outcome,
                $argusDecision?->questionsForSite(AgentDecisionModelSettings::SITE_REASONING_EFFORT)
            ));
            $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_REASONING_EFFORT, $decision->ownerMessageIndex, null, null, $outcome + ['decidedBy' => $decision->decidedBy], $runToken, $elapsedMs);
            $this->aiTrace('turn.reasoningEffort.decided', ['conversationId' => $conversation->id ?? null] + $outcome + ['decidedBy' => $decision->decidedBy]);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $applyError) {
            $this->aiTrace('turn.reasoningEffort.failed', ['conversationId' => $conversation->id ?? null, 'message' => $applyError->getMessage()]);
        }
    }

    /**
     * The agent model's CONFIGURED agentic effort and its CEILING: the catalog's `reasoningEffort`
     * (null for a model without one) and `maxReasoningEffort` when the catalog carries it, else the default ceiling.
     * Resolved the way the model call resolves its model (the per-conversation override, else the egress default) —
     * no HTTP, no entity copy.
     *
     * @param AIConversation $conversation
     * @return AgentReasoningEffortCatalogEfforts
     */
    protected function resolveConfiguredAndCeilingReasoningEffort(AIConversation $conversation): AgentReasoningEffortCatalogEfforts
    {
        try {
            $argusAIAgentConversation = new ArgusAIAgentConversation();
            $argusAIAgentConversation->aiModelNameOverride = $conversation->aiModelNameOverride ?? null;
            $agenticUseCase = $argusAIAgentConversation->getAIModel()?->agenticUseCase;
            $configuredEffort = $agenticUseCase?->reasoningEffort;
            $ceilingEffort = ($agenticUseCase !== null && property_exists($agenticUseCase, 'maxReasoningEffort'))
                ? AgentDecisionModelSettings::canonicalReasoningEffort($agenticUseCase->maxReasoningEffort ?? null)
                : null;
            return AgentReasoningEffortCatalogEfforts::forConfiguredAndCeiling(
                $configuredEffort !== null && $configuredEffort !== '' ? $configuredEffort : null,
                $ceilingEffort ?? AgentReasoningEffortDecision::DEFAULT_CEILING
            );
        } catch (Throwable) {
            return AgentReasoningEffortCatalogEfforts::forConfiguredAndCeiling(null, AgentReasoningEffortDecision::DEFAULT_CEILING);
        }
    }

    /**
     * The effort THIS model call is sent with: the owner turn's decided effort while its stamp is the
     * current initiator's and the decision applies. Without an applicable decision (the kickoff opener, a
     * signal-started chat turn, a stale stamp) the site's DEFAULT is sent while the site is `on` for this conversation
     * (the default is `low` everywhere, the opener included) — the row's pin still
     * raises it. Under `shadow`, for a delegation and for an autonomous purpose the purpose's base effort is sent when
     * it declares one (one rule with the admin baseline); null = the catalog value: nothing declared,
     * or site off.
     */
    public function resolveDecidedReasoningEffortForCurrentTurn(AIConversation $conversation, bool $conversationWorksAutonomously): ?string
    {
        // A FIXED effort (a measurement setting on the conversation) is sent as it is, clamped to the model's ceiling:
        // no site verdict, no pin, no declared minimum changes it — in a delegation and an autonomous run too. A
        // delegation child or an autonomous purpose run never asks the decision MODEL, but the
        // declared MINIMUMS (the purpose's floor, the next open plan item's) apply to them: an autonomous run
        // looped on one tool at `low` although its seed declared `minReasoningEffort: medium`.
        $agentReasoningEffortLimits = $this->buildReasoningEffortLimits($conversation, AgentDecisionModelSettings::current(), $conversationWorksAutonomously);
        $reasoningEffortDecision = $conversation->getAgentWorkingState()?->reasoningEffortDecision;
        $currentInitiatorIndex = $conversation->messages->getNewestInitiatorMessageIndex();
        $raisedEffort = $agentReasoningEffortLimits->resolveEffortRaisedByDeclaredMinimums($reasoningEffortDecision, $currentInitiatorIndex);
        if ($raisedEffort !== null) {
            $this->aiTrace('turn.reasoningEffort.minimum', [
                'conversationId' => $conversation->id ?? null,
                'from' => $agentReasoningEffortLimits->resolveEffortBeforeDeclaredMinimums($reasoningEffortDecision, $currentInitiatorIndex),
                'to' => $raisedEffort,
                'purposeMinimum' => $agentReasoningEffortLimits->purposeMinimum,
                'planItemMinimum' => $agentReasoningEffortLimits->planItemMinimum,
            ]);
        }
        return $agentReasoningEffortLimits->resolveEffortToSend($reasoningEffortDecision, $currentInitiatorIndex);
    }

    /**
     * Everything that decides the effort of this conversation's model call, read once: the model
     * catalog's configured effort and ceiling, the purpose's base effort and minimum, the conversation's own limits
     * ({@see AgentReasoningEffortLimits::fromConversation()}: site mode, row pin, fixed effort, next plan item's
     * minimum) and whether it works without an owner turn (a
     * delegation, or `$conversationWorksAutonomously`).
     */
    public function buildReasoningEffortLimits(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, bool $conversationWorksAutonomously): AgentReasoningEffortLimits
    {
        $agentReasoningEffortLimits = AgentReasoningEffortLimits::fromConversation($conversation, $decisionModelSettings);
        $catalogEfforts = $this->resolveConfiguredAndCeilingReasoningEffort($conversation);
        $agentReasoningEffortLimits->configuredEffort = $catalogEfforts->configuredEffort;
        $agentReasoningEffortLimits->ceilingEffort = $catalogEfforts->ceilingEffort;
        $agentReasoningEffortLimits->purposeBaseEffort = $this->resolvePurposeBaseReasoningEffort($conversation);
        $agentReasoningEffortLimits->purposeMinimum = $this->resolvePurposeMinReasoningEffort($conversation);
        $agentReasoningEffortLimits->worksWithoutAnOwnerTurn = $conversation->isDelegation() || $conversationWorksAutonomously;
        return $agentReasoningEffortLimits;
    }

    /**
     * {@see buildReasoningEffortLimits()} for a reader outside the loop (the admin decision-layer status): whether the
     * conversation works autonomously is read from its purpose. Never throws — an unreadable model or purpose leaves
     * only the limits the conversation itself carries (no catalog effort, the default ceiling).
     */
    public function buildReasoningEffortLimitsForConversation(AIConversation $conversation, ?AgentDecisionModelSettings $decisionModelSettings = null): AgentReasoningEffortLimits
    {
        $decisionModelSettings ??= AgentDecisionModelSettings::current();
        try {
            return $this->buildReasoningEffortLimits(
                $conversation,
                $decisionModelSettings,
                $this->purposeRunsService()->conversationPurposeWorksAutonomously($conversation)
            );
        } catch (Throwable) {
            return AgentReasoningEffortLimits::fromConversation($conversation, $decisionModelSettings);
        }
    }

    /**
     * The turn's ACTIVE effort before any decision (what the decision model is told the assistant
     * already reasons at): {@see AgentReasoningEffortLimits::buildFloor()} over the configured effort (or
     * the site default), the row pin, the purpose floor and the next open plan item's minimum, clamped to the ceiling.
     */
    protected function resolveFloorReasoningEffortForCurrentTurn(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): AgentReasoningEffortFloor
    {
        // the site runs for an owner turn only, so whether the conversation works autonomously changes nothing here
        return $this->buildReasoningEffortLimits($conversation, $decisionModelSettings, false)->buildFloor();
    }

    /**
     * The conversation purpose's declared effort floor ({@see AIConversationPurpose::$minReasoningEffort}) — null for
     * a purpose-less conversation, an unknown purpose or a purpose without one.
     */
    protected function resolvePurposeMinReasoningEffort(AIConversation $conversation): ?string
    {
        $aIConversationPurpose = $conversation->findPurpose();
        return $aIConversationPurpose instanceof AIConversationPurpose ? $aIConversationPurpose->minReasoningEffort : null;
    }

    /**
     * The conversation purpose's declared BASE effort ({@see AIConversationPurpose::$reasoningEffort}) — what a call
     * is sent with when nothing raises it, in place of the catalog's agentic effort and the global default. Null for a
     * purpose-less conversation, an unknown purpose or a purpose without one.
     */
    protected function resolvePurposeBaseReasoningEffort(AIConversation $conversation): ?string
    {
        $aIConversationPurpose = $conversation->findPurpose();
        return $aIConversationPurpose instanceof AIConversationPurpose ? $aIConversationPurpose->reasoningEffort : null;
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return $aIConversationPurposeRunsService;
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }

    protected function turnEventsService(): AIConversationTurnEventsService
    {
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        return $aIConversationTurnEventsService;
    }
}
