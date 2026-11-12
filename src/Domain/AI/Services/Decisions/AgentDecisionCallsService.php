<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionBatchInFlight;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Exceptions\MissingAIEnvelopeException;
use DDD\Domain\AI\Exceptions\MissingAIScopeException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Exception\TransferException;
use Throwable;

/**
 * The ONE runner of decision-model calls: the circuit breaker's check, the call itself and
 * the classification of how it went ({@see AgentDecisionCallOutcome}) — a transport failure trips the breaker, our own
 * gate's refusal and our own error never do; only a parsable answer closes it ({@see closeCircuitBreakerAfter()}).
 * What the answer means — whether it is parsable — stays with the site that asked.
 */
class AgentDecisionCallsService extends Service
{
    use AITraceLogTrait;

    /**
     * Run a decision batch: an open breaker makes no call ({@see AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN}),
     * otherwise {@see runDecisionBatchWithoutBreakerCheck()}. `$primaryDecision` (an object using
     * ArgusAgentDecisionTrait) is the child whose answer the site reads: a transport failure of ANOTHER child still
     * trips the breaker (it reads the whole batch) but leaves the call ANSWERED with
     * {@see AgentDecisionCallOutcome::$siblingTransportFailed}; null when the batch as a whole is the site's answer.
     * `$siteLabel` names the site in the log line of our own error and in a trip reason without a primary.
     */
    public function runDecisionBatch(ArgusAgentDecisions $decisionBatch, ?object $primaryDecision, float $timeoutSeconds, int $breakerSeconds, string $siteLabel): AgentDecisionCallOutcome
    {
        if ($this->isCircuitBreakerOpen()) {
            return AgentDecisionCallOutcome::build(AgentDecisionCallOutcome::OUTCOME_BREAKER_OPEN);
        }
        return $this->runDecisionBatchWithoutBreakerCheck($decisionBatch, $primaryDecision, $timeoutSeconds, $breakerSeconds, $siteLabel);
    }

    /**
     * Whether the shared breaker is open — asked by a site that builds its batch only when a call will be made
     * (the turn start, the sub-skill pass after a load of the chat model); it then runs the batch through
     * {@see runDecisionBatchWithoutBreakerCheck()}.
     */
    public function isCircuitBreakerOpen(): bool
    {
        return $this->circuitBreaker()->isOpen();
    }

    /**
     * The call and its classification ({@see runDecisionBatch()}) for a caller that has just found the breaker closed
     * ({@see isCircuitBreakerOpen()}) and built its batch after that check.
     */
    public function runDecisionBatchWithoutBreakerCheck(ArgusAgentDecisions $decisionBatch, ?object $primaryDecision, float $timeoutSeconds, int $breakerSeconds, string $siteLabel): AgentDecisionCallOutcome
    {
        $decisionCallOutcome = $this->loadDecisionBatchAndClassifyOutcome($decisionBatch, $primaryDecision, $timeoutSeconds, $siteLabel);
        $this->tripCircuitBreakerAfter($decisionCallOutcome, $breakerSeconds, $siteLabel);
        return $decisionCallOutcome;
    }

    /**
     * {@see runDecisionBatchWithoutBreakerCheck()} in two halves, for a caller with work of its own while the answers
     * travel: this half SENDS the batch's requests; {@see receiveDecisionBatch()} waits for them, classifies and trips
     * the breaker exactly as the one-shot call. What the send throws is kept and classified on receive.
     */
    public function sendDecisionBatchWithoutBreakerCheck(ArgusAgentDecisions $decisionBatch, ?object $primaryDecision, float $timeoutSeconds, int $breakerSeconds, string $siteLabel): AgentDecisionBatchInFlight
    {
        $agentDecisionBatchInFlight = new AgentDecisionBatchInFlight();
        $agentDecisionBatchInFlight->decisionBatch = $decisionBatch;
        $agentDecisionBatchInFlight->primaryDecision = $primaryDecision;
        $agentDecisionBatchInFlight->breakerSeconds = $breakerSeconds;
        $agentDecisionBatchInFlight->siteLabel = $siteLabel;
        $agentDecisionBatchInFlight->callStartedAtHr = hrtime(true);
        try {
            $decisionBatch->sendDecisionRequests($timeoutSeconds);
        } catch (Throwable $sendError) {
            $agentDecisionBatchInFlight->sendError = $sendError;
        }
        return $agentDecisionBatchInFlight;
    }

    /** Wait for the batch {@see sendDecisionBatchWithoutBreakerCheck()} sent, classify how it went and trip the breaker. */
    public function receiveDecisionBatch(AgentDecisionBatchInFlight $agentDecisionBatchInFlight): AgentDecisionCallOutcome
    {
        $loadError = $agentDecisionBatchInFlight->sendError;
        if ($loadError === null) {
            try {
                $agentDecisionBatchInFlight->decisionBatch->receiveDecisionResponses();
            } catch (Throwable $receiveError) {
                $loadError = $receiveError;
            }
        }
        $decisionCallOutcome = $this->classifyDecisionBatchOutcome($agentDecisionBatchInFlight->decisionBatch, $agentDecisionBatchInFlight->primaryDecision, $agentDecisionBatchInFlight->siteLabel, $agentDecisionBatchInFlight->callStartedAtHr, $loadError);
        $this->reclassifyTimeoutSpentOnOwnWork($decisionCallOutcome, $agentDecisionBatchInFlight->decisionBatch);
        $this->tripCircuitBreakerAfter($decisionCallOutcome, $agentDecisionBatchInFlight->breakerSeconds, $agentDecisionBatchInFlight->siteLabel);
        return $decisionCallOutcome;
    }

    /**
     * A split call whose missing answer says nothing about the model or the network: the caller's own work between the
     * send and the receive took longer than the decision timeout and left the model less than its time — the transport
     * timeout ran out before the receive, or the requests were not yet written then
     * ({@see ArgusAgentDecisions::wasDecisionTimeoutSpentOnTheCallersOwnWork()}) — and no failing child got an HTTP
     * status. A request written before the receive had its full decision timeout, so its miss still trips. The
     * primary's failure becomes {@see AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK}, a sibling's no
     * longer counts — neither trips the breaker; the sites fall back as on any call without an answer.
     */
    protected function reclassifyTimeoutSpentOnOwnWork(AgentDecisionCallOutcome $decisionCallOutcome, ArgusAgentDecisions $decisionBatch): void
    {
        if (!$decisionCallOutcome->childrenSettled || !$decisionBatch->wasDecisionTimeoutSpentOnTheCallersOwnWork() || $decisionBatch->hasTransportFailureWithAnHttpStatus()) {
            return;
        }
        if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED) {
            $decisionCallOutcome->failureReason = 'the decision timeout was spent on our own work before the receive (' . $decisionCallOutcome->failureReason . ')';
            $decisionCallOutcome->outcome = AgentDecisionCallOutcome::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK;
        }
        $decisionCallOutcome->siblingTransportFailed = false;
    }

    /** A transport failure of the call, or of a sibling child of its batch, trips the shared breaker. */
    protected function tripCircuitBreakerAfter(AgentDecisionCallOutcome $decisionCallOutcome, int $breakerSeconds, string $siteLabel): void
    {
        if ($decisionCallOutcome->outcome === AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED) {
            $this->circuitBreaker()->trip($breakerSeconds, (string)$decisionCallOutcome->failureReason);
        } elseif ($decisionCallOutcome->siblingTransportFailed) {
            $this->circuitBreaker()->trip($breakerSeconds, "a sibling request of the {$siteLabel} batch failed at the transport level");
        }
    }

    /** The load of the batch and the classification of how it went — the breaker is the caller's. */
    protected function loadDecisionBatchAndClassifyOutcome(ArgusAgentDecisions $decisionBatch, ?object $primaryDecision, float $timeoutSeconds, string $siteLabel): AgentDecisionCallOutcome
    {
        $callStartedAt = hrtime(true);
        $loadError = null;
        try {
            $decisionBatch->loadDecisions($timeoutSeconds);
        } catch (Throwable $thrownByTheLoad) {
            $loadError = $thrownByTheLoad;
        }
        return $this->classifyDecisionBatchOutcome($decisionBatch, $primaryDecision, $siteLabel, $callStartedAt, $loadError);
    }

    /**
     * How a decision batch went: answered (a primary or a sibling child may still have failed at the transport level),
     * refused by our own gate (budget, scope, envelope), failed at the transport level, or failed in our own code
     * (logged) — `$loadError` is what the load threw, null when it returned.
     */
    protected function classifyDecisionBatchOutcome(ArgusAgentDecisions $decisionBatch, ?object $primaryDecision, string $siteLabel, int|float $callStartedAt, ?Throwable $loadError): AgentDecisionCallOutcome
    {
        $siblingTransportFailed = false;
        $childrenSettled = false;
        $thrownExceptionSummary = null;
        if ($loadError === null) {
            $childrenSettled = true;
            $outcome = AgentDecisionCallOutcome::OUTCOME_ANSWERED;
            $failureReason = null;
            if ($decisionBatch->hasTransportFailure()) {
                if ($primaryDecision === null) {
                    $outcome = AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED;
                    $failureReason = "a request of the {$siteLabel} batch failed at the transport level";
                } elseif (($primaryDecision->transportFailed ?? false) === true) {
                    $outcome = AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED;
                    $failureReason = $primaryDecision->transportFailureReason ?? ('http ' . ($primaryDecision->httpStatusCode ?? 'none'));
                } else {
                    $siblingTransportFailed = true;
                }
            }
        } elseif ($loadError instanceof AiBudgetExceededException || $loadError instanceof AIScopeNotEligibleException
            || $loadError instanceof MissingAIEnvelopeException || $loadError instanceof MissingAIScopeException
        ) {
            $outcome = AgentDecisionCallOutcome::OUTCOME_REFUSED;
            $failureReason = $loadError::class;
            $thrownExceptionSummary = AgentDecisionCallOutcome::summarizeThrownException($loadError);
        } elseif ($loadError instanceof TransferException) {
            $outcome = AgentDecisionCallOutcome::OUTCOME_TRANSPORT_FAILED;
            $failureReason = $loadError::class . ': ' . mb_substr($loadError->getMessage(), 0, 160);
            $thrownExceptionSummary = AgentDecisionCallOutcome::summarizeThrownException($loadError);
        } else {
            $outcome = AgentDecisionCallOutcome::OUTCOME_ERROR;
            $failureReason = $loadError::class . ': ' . mb_substr($loadError->getMessage(), 0, 200);
            $thrownExceptionSummary = AgentDecisionCallOutcome::summarizeThrownException($loadError);
            DDDService::instance()->getLogger()->warning("[AgentDecisionModel] {$siteLabel} decision failed in our code", ['reason' => $failureReason]);
        }
        $decisionCallOutcome = AgentDecisionCallOutcome::build($outcome, $failureReason, (int)round((hrtime(true) - $callStartedAt) / 1e6));
        $decisionCallOutcome->siblingTransportFailed = $siblingTransportFailed;
        $decisionCallOutcome->childrenSettled = $childrenSettled;
        $decisionCallOutcome->thrownExceptionSummary = $thrownExceptionSummary;
        return $decisionCallOutcome;
    }

    /**
     * Append ONE decision-model call to the conversation's bounded decision log ({@see AgentWorkingState::$decisions})
     * and persist that slice — the inspector's trace of what was sent, what came back and what PHP made of it. The
     * record is the site's ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecision::buildRecord()}).
     * Best-effort: never throws into the loop, except the lost run claim.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function recordDecision(AIConversation $conversation, AgentDecisionRecord $agentDecisionRecord): void
    {
        try {
            $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
            $agentWorkingState->appendDecisionRecord($agentDecisionRecord);
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'decisions');
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $recordError) {
            $this->aiTrace('turn.decision.recordFailed', ['conversationId' => $conversation->id ?? null, 'message' => $recordError->getMessage()]);
        }
    }

    /**
     * Close the breaker after a call, by the ONE rule of every site: only a
     * PARSABLE answer closes it (`$answerUsable` — the site's own reading of the answer). An unparsable answer, an
     * HTTP status error, a call without an answer, or one whose sibling child failed at the transport level (the runner
     * tripped the breaker on it) leaves it as it is.
     */
    public function closeCircuitBreakerAfter(AgentDecisionCallOutcome $decisionCallOutcome, bool $answerUsable): void
    {
        if (!$decisionCallOutcome->answered() || $decisionCallOutcome->siblingTransportFailed || !$answerUsable) {
            return;
        }
        $this->closeCircuitBreaker();
    }

    /** A site's parsable answer closes the breaker (an unparsable one leaves it as it is). */
    public function closeCircuitBreaker(): void
    {
        $this->circuitBreaker()->close();
    }

    protected function circuitBreaker(): AgentDecisionModelCircuitBreakerService
    {
        /** @var AgentDecisionModelCircuitBreakerService $agentDecisionModelCircuitBreakerService */
        $agentDecisionModelCircuitBreakerService = DDDService::instance()->getService(AgentDecisionModelCircuitBreakerService::class);
        return $agentDecisionModelCircuitBreakerService;
    }
}
