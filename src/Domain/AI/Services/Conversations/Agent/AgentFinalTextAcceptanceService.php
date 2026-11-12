<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentFinalTextAcceptance;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnClosingDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentFinalTextAcceptanceResult;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentFinalTextClassification;

/**
 * Final-text acceptance (layer L2): whether a final answer is accepted or bounced as an
 * announcement — the turn-closing decision model asked over the answer and the plan's open work (only when the pure
 * gate of {@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy} lets it matter),
 * its verdict stamped on the working state and recorded. The checked-stop bounce itself is the policy's.
 */
class AgentFinalTextAcceptanceService extends Service
{
    use AITraceLogTrait;

    /**
     * The turn-CLOSING decision on a final answer (site `closing`): when the model ends the turn
     * in prose while plan items still owe evidence and no work tool ran, ask the decision model whether the text
     * ANNOUNCES that work instead of delivering it. Returns true when the loop must bounce (the same checked-stop
     * bounce the deterministic guard produces). The verdict is stamped as its own working-state slice
     * ({@see AgentWorkingState::$turnClosingDecision}) for the admin trace and the gate measurement.
     *
     * Gates and guarantees mirror the reply-language site ({@see AgentReplyLanguageDecisionsService}): the config's `mode: off` or the site not listed →
     * false before anything else; `shadow` records the verdict and returns false; the shared circuit breaker is
     * checked first; a transport failure trips it, a budget / envelope / scope refusal or our own error never does;
     * every path is best-effort — a failure here can never wedge the turn, it only means "no bounce".
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function decideTurnClosing(
        AIConversation $conversation,
        ?TaskPlan $taskPlan,
        string $answerText,
        int $bounceSignalsInCurrentChunk,
        ?string $turnClosingMode = null
    ): AgentTurnClosingOutcome {
        try {
            $decisionModelSettings = AgentDecisionModelSettings::current();
            // the site mode for THIS conversation (the env veto, the kill switch, the row)
            $closingSiteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_TURN_CLOSING, $conversation);
            if ($closingSiteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation()) {
                return AgentTurnClosingOutcome::unavailable(AgentTurnClosingOutcome::REASON_SITE_OFF);
            }
            $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
            if (!AgentCheckedStopPolicy::shouldAskTurnClosingDecision(
                $taskPlan,
                $currentTurnInitiatorMessageIndex,
                $bounceSignalsInCurrentChunk,
                $conversation->messages->buildCurrentTurn()->readsSubAgentResults(), // notification turns too
                $conversation->messages->buildCurrentTurn()->hasCompletedWorkToolCall(),
                $answerText
            )) {
                return AgentTurnClosingOutcome::unavailable(AgentTurnClosingOutcome::REASON_NOT_ASKED);
            }
            $openWorkItems = $taskPlan?->getOpenItemPathTexts() ?? [];

            $turnClosingDecision = new AgentTurnClosingDecision();
            $turnClosingDecision->ownerMessageIndex = $currentTurnInitiatorMessageIndex;
            $turnClosingDecision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
            $turnClosingDecision->openWorkItemCount = count($openWorkItems);
            $turnClosingDecision->answerLength = mb_strlen($answerText);
            $turnClosingDecision->turnClosingMode = $turnClosingMode;

            $transportFailureReason = null;
            $rawResponseExcerpt = null;
            $argusTurnClosingDecision = new ArgusAgentTurnClosingDecision();
            $argusTurnClosingDecision->assistantAnswer = $answerText;
            $argusTurnClosingDecision->openWorkItems = $openWorkItems;
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $argusTurnClosingDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
            $argusTurnClosingDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
            $argusTurnClosingDecision->setTimeoutSeconds($decisionModelSettings->timeoutSeconds);
            // the final-text batch — its own boundary (it needs the final text), one child
            $decisionBatch = ArgusAgentDecisions::forChild($argusTurnClosingDecision, 'turnClosing');
            $decisionCallOutcome = $this->decisionCallsService()->runDecisionBatch(
                $decisionBatch,
                $argusTurnClosingDecision,
                $decisionModelSettings->timeoutSeconds,
                $decisionModelSettings->breakerSeconds,
                'turn-closing'
            );
            $transportFailureReason = $decisionCallOutcome->failureReason;
            if ($decisionCallOutcome->childrenSettled) {
                $rawResponse = $argusTurnClosingDecision->lastRawResponse ?? null;
                $rawResponseExcerpt = $rawResponse !== null ? mb_substr((string)json_encode($rawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400) : null;
            }
            if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_REFUSED) {
                // Our own gate said no — not an outage, never a breaker trip; recorded as its own non-verdict
                // so the ladder fallback under `decisionModel` is visible on the decision record and in the stats.
                $this->aiTrace('turn.closing.skipped', ['conversationId' => $conversation->id ?? null, 'reason' => $transportFailureReason]);
            }
            // the PER-SITE apply (the language site always did this) — `turnClosing:shadow` under a global
            // `on` used to apply the verdict through the global reader.
            $turnClosingDecision->decidedBy = $decisionCallOutcome->resolveDecidedBy($argusTurnClosingDecision->decided, $closingSiteMode);
            $this->decisionCallsService()->closeCircuitBreakerAfter($decisionCallOutcome, $argusTurnClosingDecision->decided);
            if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN) {
                $argusTurnClosingDecision = null;
            } elseif ($turnClosingDecision->isDecidedByTheModel()) {
                $turnClosingDecision->announcesInsteadOfDeliversProbability = $argusTurnClosingDecision->announcesInsteadOfDeliversProbability;
                $turnClosingDecision->reportsCompletedWritesProbability = $argusTurnClosingDecision->reportsCompletedWritesProbability;
                $turnClosingDecision->modelVersion = $argusTurnClosingDecision->modelVersion;
            }
            $turnClosingDecision->wouldBounce = AgentTurnClosingDecision::resolveWouldBounce($turnClosingDecision->announcesInsteadOfDeliversProbability);
            $outcomeReason = match ($turnClosingDecision->decidedBy) {
                AgentTurnClosingDecision::DECIDED_BY_DECISION_MODEL => null,
                AgentTurnClosingDecision::DECIDED_BY_SHADOW => AgentTurnClosingOutcome::REASON_SHADOW,
                AgentTurnClosingDecision::FALLBACK_BREAKER => AgentTurnClosingOutcome::REASON_BREAKER,
                AgentTurnClosingDecision::FALLBACK_TRANSPORT => AgentTurnClosingOutcome::REASON_TRANSPORT,
                AgentTurnClosingDecision::FALLBACK_UNPARSABLE => AgentTurnClosingOutcome::REASON_UNPARSABLE,
                AgentTurnClosingDecision::FALLBACK_REFUSED => AgentTurnClosingOutcome::REASON_REFUSED,
                default => AgentTurnClosingOutcome::REASON_ERROR,
            };
            // A FALLBACK exists only under `decisionModel` (under `deterministicCounter` the ladder already ran first)
            // — stamped for the decision record.
            $turnClosingDecision->fallbackReason = $turnClosingMode !== null && $turnClosingMode !== AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER ? $outcomeReason : null;

            $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
            $agentWorkingState->turnClosingDecision = $turnClosingDecision;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'turnClosingDecision');
            $this->decisionCallsService()->recordDecision($conversation, $turnClosingDecision->buildRecord(
                AgentDecisionModelSettings::SITE_TURN_CLOSING,
                $turnClosingDecision->isApplied(),
                $decisionCallOutcome->elapsedMs,
                $transportFailureReason,
                $argusTurnClosingDecision?->buildState(),
                $argusTurnClosingDecision?->rawAnswers,
                [
                    'applied' => $turnClosingDecision->isApplied(),
                    'wouldBounce' => $turnClosingDecision->wouldBounce,
                    'bounced' => $turnClosingDecision->isApplied() && $turnClosingDecision->wouldBounce,
                    'turnClosingMode' => $turnClosingMode,
                    'fallbackReason' => $turnClosingDecision->fallbackReason,
                    'announcesInsteadOfDeliversProbability' => $turnClosingDecision->announcesInsteadOfDeliversProbability,
                    'reportsCompletedWritesProbability' => $turnClosingDecision->reportsCompletedWritesProbability,
                    'minimumAnnounceProbability' => AgentTurnClosingDecision::MINIMUM_ANNOUNCE_PROBABILITY,
                ]
            ));
            // the DURABLE row of this decision (the record on the working state is the bounded log) —
            // the two-arm measurement reads decisions, fallbacks and their latency from the events table.
            $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_TURN_CLOSING, $currentTurnInitiatorMessageIndex, null, null, [
                'decidedBy' => $turnClosingDecision->decidedBy,
                'turnClosingMode' => $turnClosingMode,
                'fallbackReason' => $turnClosingDecision->fallbackReason,
                'applied' => $turnClosingDecision->isApplied(),
                'wouldBounce' => $turnClosingDecision->wouldBounce,
                'announcesInsteadOfDeliversProbability' => $turnClosingDecision->announcesInsteadOfDeliversProbability,
                'reportsCompletedWritesProbability' => $turnClosingDecision->reportsCompletedWritesProbability,
                'openWorkItemCount' => $turnClosingDecision->openWorkItemCount,
                'answerLength' => $turnClosingDecision->answerLength,
                'modelVersion' => $turnClosingDecision->modelVersion,
                'transportFailureReason' => $transportFailureReason,
                'batchId' => $decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN ? null : $decisionBatch->batchId, // no batch is sent past an open breaker
            ], isset($conversation->runToken) ? $conversation->runToken : null, $decisionCallOutcome->elapsedMs);
            $this->aiTrace('turn.closing.decided', [
                'conversationId' => $conversation->id ?? null,
                'ownerMessageIndex' => $currentTurnInitiatorMessageIndex,
                'decidedBy' => $turnClosingDecision->decidedBy,
                'announces' => $turnClosingDecision->announcesInsteadOfDeliversProbability,
                'reportsCompletedWrites' => $turnClosingDecision->reportsCompletedWritesProbability,
                'wouldBounce' => $turnClosingDecision->wouldBounce,
                'applied' => $turnClosingDecision->isApplied(),
                'openWorkItems' => implode('; ', $openWorkItems),
                'modelVersion' => $turnClosingDecision->modelVersion,
                'transportFailureReason' => $transportFailureReason,
                'rawResponseExcerpt' => $rawResponseExcerpt,
                'elapsedMs' => $decisionCallOutcome->elapsedMs,
            ]);
            return $turnClosingDecision->isApplied()
                ? AgentTurnClosingOutcome::verdict($turnClosingDecision->wouldBounce, $turnClosingDecision)
                : AgentTurnClosingOutcome::unavailable((string)$outcomeReason, $turnClosingDecision);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $decisionError) {
            $this->aiTrace('turn.closing.failed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionError->getMessage()]);
            return AgentTurnClosingOutcome::unavailable(AgentTurnClosingOutcome::REASON_ERROR);
        }
    }

    /**
     * The deterministic counter's verdict on a final text ({@see AgentCheckedStopPolicy::shouldBounceCheckedStop()}, the
     * one rule) over the turn as the loop holds it — only where the classification lets a guard judge the text at all;
     * the opener of a kickoff the present owner has not answered is never rejected.
     */
    public function decideCounterBounce(
        AIConversation $conversation,
        ?TaskPlan $currentTaskPlan,
        int $bounceSignalsInCurrentChunk,
        ?TaskPlanEvidence $checkedStopTaskPlanEvidence,
        AgentFinalTextClassification $finalTextClassification
    ): bool {
        return $finalTextClassification->decisionModelMayJudge && AgentCheckedStopPolicy::shouldBounceCheckedStop(
            taskPlan: $currentTaskPlan,
            // The STABLE initiator message index, never the user-turn COUNT — the count is derived over the
            // LOADED window and shifts when a compaction landing between chunks covers earlier turns, which
            // would silently disarm the bounce mid-task.
            currentTurnInitiatorMessageIndex: $conversation->messages->getNewestInitiatorMessageIndex() ?? -1,
            bounceSignalsInCurrentChunk: $bounceSignalsInCurrentChunk,
            turnInitiatedByWorkSignal: $conversation->messages->hasWorkSignalAsNewestInboundMessage(),
            finalTextHandsTheTurnToTheOwner: $finalTextClassification->handsTheTurnToTheOwner($conversation),
            candidateTextEvidence: $checkedStopTaskPlanEvidence,
            // the opener of a conversation the present owner has not answered yet — never under an autonomous purpose,
            // whose kickoff owes the work itself
            answersKickoffBeforeTheOwnerSpoke: !$finalTextClassification->counterFirstMandatory
                && $conversation->messages->isKickoffBeforeTheOwnerSpoke()
                && $conversation->getOwnerPresence($conversation->messages->getElements())?->isPersonPresent() === true
        );
    }

    /**
     * The checked stop's verdict on a final text, recorded: whether the loop bounces it —
     * never the budget closing step's answer, partial BY DESIGN (bouncing it would spend the one call the owner's message
     * had on a signal nobody can act on); the resolver's rule written onto the record of the decision it consumed (the
     * record is written inside the decision call, before the rule is known — only when the decision model was asked for THIS text); a
     * fallback traced.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the decision record was persisted
     */
    public function recordVerdictAndDecideBounce(AIConversation $conversation, AgentFinalTextAcceptanceResult $checkedStopVerdict, bool $budgetClosingStep): bool
    {
        $bounceCheckedStop = $checkedStopVerdict->bounce;
        if ($budgetClosingStep && $bounceCheckedStop) {
            // The closing step's answer is partial BY DESIGN — bouncing it for open items would spend the one
            // call the owner's message had on a signal nobody can act on.
            $this->aiTrace('turn.budgetClosing.bounceSkipped', ['conversationId' => $conversation->id ?? null]);
            $bounceCheckedStop = false;
        }
        // the resolver's rule on the record of the verdict it consumed (the record is written inside the
        // decision call, before the rule is known) — only when the decision model was asked for THIS text
        if ($checkedStopVerdict->outcome !== null
            && isset($conversation->agentWorkingState) && $conversation->agentWorkingState->turnClosingDecision !== null
            && $conversation->agentWorkingState->turnClosingDecision->decidingRule === null
        ) {
            $conversation->agentWorkingState->turnClosingDecision->decidingRule = $checkedStopVerdict->decidingRule;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'turnClosingDecision');
        }
        if ($checkedStopVerdict->fallbackReason !== null && $checkedStopVerdict->fallbackReason !== AgentTurnClosingOutcome::REASON_NOT_ASKED) {
            $this->aiTrace('turn.closing.fallback', [
                'conversationId' => $conversation->id ?? null,
                'reason' => $checkedStopVerdict->fallbackReason,
                'deterministicBounced' => $bounceCheckedStop,
            ]);
        }
        return $bounceCheckedStop;
    }

    /** The text of the checked-stop bounce ({@see AgentCheckedStopPolicy::buildCheckedStopBounceText()}). */
    public function buildCheckedStopBounceText(AIConversation $conversation, ?TaskPlan $currentTaskPlan): string
    {
        return AgentCheckedStopPolicy::buildCheckedStopBounceText($currentTaskPlan, $conversation->messages->buildCurrentTurn()->readsSubAgentResults());
    }

    /**
     * After the bounce signal is persisted: the decision record's cue that THIS verdict caused the bounce
     * (the cap-1 rule reads the signal — a decision-model-CONFIRMED counter bounce is the verdict's doing too), then
     * the bounce traced.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the decision record was persisted
     */
    public function recordBounceOfVerdict(
        AIConversation $conversation,
        AgentFinalTextAcceptanceResult $checkedStopVerdict,
        string $checkedStopTurnClosingMode,
        int $bounceSignalsInCurrentChunk,
        ?TaskPlan $currentTaskPlan
    ): void {
        $checkedStopBounceTrigger = $checkedStopVerdict->trigger;
        if (($checkedStopBounceTrigger === AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL
                || $checkedStopVerdict->decidingRule === AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL_CONFIRMED_COUNTER)
            && isset($conversation->agentWorkingState) && $conversation->agentWorkingState->turnClosingDecision !== null
        ) {
            // the record's cue that THIS verdict caused the bounce (the cap-1 rule reads the signal);
            // a decision-model-CONFIRMED counter bounce is the verdict's doing too
            $conversation->agentWorkingState->turnClosingDecision->bounced = true;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'turnClosingDecision');
        }
        $this->aiTrace('turn.checkedStop.bounced', [
            'conversationId' => $conversation->id ?? null,
            'trigger' => $checkedStopBounceTrigger,
            'turnClosingMode' => $checkedStopTurnClosingMode,
            'decidingRule' => $checkedStopVerdict->decidingRule,
            'bounceLevel' => $bounceSignalsInCurrentChunk + 1,
            'openItems' => implode('; ', $currentTaskPlan->getOpenItemPathTexts()),
        ]);
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

    /**
     * The checked stop of a candidate final text (moved from the agent loop:
     * every conversation, one rule): ONE resolver decides which guard judges the text (the deterministic counter,
     * the decision model, in the order the closing mode asks); a bounce DISCARDS the text — its usage rides the bounce
     * signal — and is recorded. Answers whether the text was bounced (the loop re-drives the model).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function bounceCandidateTextIfTheCheckedStopAsks(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ModelResponse $modelResponse,
        ?string $modelName,
        ?TaskPlan $currentTaskPlan,
        ?TaskPlanEvidence $checkedStopTaskPlanEvidence,
        AgentFinalTextClassification $finalTextClassification,
        int $bounceSignalsInCurrentChunk,
        bool $budgetClosingStep,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): bool {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        // the ONE deterministic rule judges every conversation; the turn-closing decision model is asked
        // only where it was asked before — under an autonomous purpose (counter first). A conversational purpose and a
        // plain chat run the counter alone: no decision call, no added latency, no owner question discarded by a verdict.
        $checkedStopTurnClosingMode = $finalTextClassification->counterFirstMandatory
            ? AgentDecisionModelSettings::current()->turnClosingModeForConversation($conversation)
            : AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;
        // each guard runs only when the resolver asks for it, in the order it asks (the decision call is a model call)
        $checkedStopAcceptance = AgentFinalTextAcceptance::start(
            turnClosingMode: $checkedStopTurnClosingMode,
            counterFirstMandatory: $finalTextClassification->counterFirstMandatory,
            priorDecisionBounceInTurn: $conversation->messages->buildCurrentTurn()->countDecisionBounces() > 0,
            decisionModelMayJudge: $finalTextClassification->decisionModelMayJudge && $finalTextClassification->counterFirstMandatory
        );
        while (!$checkedStopAcceptance->isResolved()) {
            if ($checkedStopAcceptance->needsCounterVerdict()) {
                $checkedStopAcceptance->applyCounterVerdict($this->decideCounterBounce(
                    $conversation,
                    $currentTaskPlan,
                    $bounceSignalsInCurrentChunk,
                    $checkedStopTaskPlanEvidence,
                    $finalTextClassification
                ));
                continue;
            }
            $checkedStopAcceptance->applyDecisionOutcome($this->decideTurnClosing(
                $conversation,
                $currentTaskPlan,
                (string)($modelResponse->text ?? ''),
                $bounceSignalsInCurrentChunk,
                $checkedStopTurnClosingMode
            ));
        }
        $checkedStopVerdict = $checkedStopAcceptance->result;
        if ($this->recordVerdictAndDecideBounce($conversation, $checkedStopVerdict, $budgetClosingStep)) {
            $aIConversationMessagesService->appendLoopSignalWithStepUsage(
                $agentRun,
                $conversation,
                $transientMessageIds,
                $this->buildCheckedStopBounceText($conversation, $currentTaskPlan),
                $checkedStopVerdict->signalSource(),
                $modelName,
                $modelResponse,
                true,
                $consoleStream,
                $emitRealtimeSignal
            );
            $this->recordBounceOfVerdict($conversation, $checkedStopVerdict, $checkedStopTurnClosingMode, $bounceSignalsInCurrentChunk, $currentTaskPlan);
            return true;
        }
        return false;
    }
}
