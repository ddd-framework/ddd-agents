<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;
use Throwable;

/**
 * How one decision-model call went, classified the same way at every site: it ANSWERED (whether the
 * answer is parsable is the site's question), the circuit breaker was OPEN (no call made), our own gate REFUSED it
 * (budget, scope eligibility, a missing envelope or scope — the breaker never trips for those), the TRANSPORT failed
 * (the breaker trips), our own work between a split call's send and its receive OUTLASTED the decision timeout and no
 * answer came (the breaker never trips — nothing was learned about the model or the network), or our own code ERRORED
 * (logged, the breaker never trips).
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionCallOutcome extends ValueObject
{
    public const string OUTCOME_ANSWERED = 'answered';
    public const string OUTCOME_BREAKER_OPEN = 'breakerOpen';
    public const string OUTCOME_REFUSED = 'refused';
    public const string OUTCOME_TRANSPORT_FAILED = 'transportFailed';
    public const string OUTCOME_ERROR = 'error';
    public const string OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK = 'timeoutSpentOnOwnWork';

    public string $outcome = self::OUTCOME_ANSWERED;

    /** The reason of a refusal, a transport failure, a timeout spent on our own work or our own error — null when the call answered. */
    public ?string $failureReason = null;

    /** The call's milliseconds, null when no call was made. */
    public ?int $elapsedMs = null;

    /** The site's own request answered, but another child of its batch failed at the transport level (the breaker tripped). */
    public bool $siblingTransportFailed = false;

    /** The batch's load returned, so every child carries its own result (its answer or its transport failure). */
    public bool $childrenSettled = false;

    /**
     * The exception the call threw, as `Class: message` with the message cut at 160 characters — a refusal of our own
     * gate, a thrown transport error or our own error; null when the load returned. The failure text of the tool-side
     * checks of the owner's words (the host's owner-words decisions service).
     */
    public ?string $thrownExceptionSummary = null;

    public static function build(string $outcome, ?string $failureReason = null, ?int $elapsedMs = null): static
    {
        $decisionCallOutcome = new static();
        $decisionCallOutcome->outcome = $outcome;
        $decisionCallOutcome->failureReason = $failureReason;
        $decisionCallOutcome->elapsedMs = $elapsedMs;
        return $decisionCallOutcome;
    }

    /** `Class: message` of a thrown exception, the message cut at 160 characters ({@see $thrownExceptionSummary}). */
    public static function summarizeThrownException(Throwable $thrownException): string
    {
        return $thrownException::class . ': ' . mb_substr($thrownException->getMessage(), 0, 160);
    }

    public function answered(): bool
    {
        return $this->outcome === self::OUTCOME_ANSWERED;
    }

    /**
     * The fallback stamp of a call the site gets no answer from — ONE rule for every site:
     * `fallback:breaker` (no call made), `fallback:refused` (our own gate: budget, scope, envelope),
     * `fallback:transport`, `fallback:ownWork` (our own work outlasted the decision timeout), `fallback:error` (our own
     * code). Null for a call that answered: whether that answer is usable
     * is the site's question ({@see resolveDecidedBy()}).
     */
    public function resolveFallbackStamp(): ?string
    {
        return match ($this->outcome) {
            self::OUTCOME_BREAKER_OPEN => AgentDecision::FALLBACK_BREAKER,
            self::OUTCOME_REFUSED => AgentDecision::FALLBACK_REFUSED,
            self::OUTCOME_ERROR => AgentDecision::FALLBACK_ERROR,
            self::OUTCOME_TRANSPORT_FAILED => AgentDecision::FALLBACK_TRANSPORT,
            self::OUTCOME_TIMEOUT_SPENT_ON_OWN_WORK => AgentDecision::FALLBACK_OWN_WORK,
            default => null,
        };
    }

    /**
     * Who decided a one-child site's stamp: the fallback stamp of a call without an answer ({@see resolveFallbackStamp()}),
     * `fallback:unparsable` for an answer the site cannot read (`$answerUsable` false), else the model —
     * `jev` under the site mode `on`, `shadow` under every other mode.
     */
    public function resolveDecidedBy(bool $answerUsable, string $siteMode): string
    {
        if (!$this->answered()) {
            return (string)$this->resolveFallbackStamp();
        }
        if (!$answerUsable) {
            return AgentDecision::FALLBACK_UNPARSABLE;
        }
        return $siteMode === AgentDecisionModelSettings::MODE_ON ? AgentDecision::DECIDED_BY_DECISION_MODEL : AgentDecision::DECIDED_BY_SHADOW;
    }
}
