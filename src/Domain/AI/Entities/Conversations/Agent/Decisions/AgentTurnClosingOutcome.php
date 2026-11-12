<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The TYPED outcome of one turn-closing decision call: either a VERDICT the loop may act on
 * (`wouldBounce` with the stamped decision behind it) or UNAVAILABLE with the reason — the site is off for this
 * conversation, the gate did not hold, the breaker is open, the transport failed, the answer was malformed, our own
 * code failed, the budget / envelope / scope gate refused the call, or the site runs in shadow (recorded only). The
 * checked-stop resolver maps every UNAVAILABLE outcome to today's deterministic ladder — a bool could not carry the
 * difference between "the decision model said no" and "the decision model could not answer".
 *
 * @phpstan-consistent-constructor
 */
class AgentTurnClosingOutcome extends ValueObject
{
    public const string KIND_VERDICT = 'verdict';
    public const string KIND_UNAVAILABLE = 'unavailable';

    public const string REASON_SITE_OFF = 'siteOff';
    public const string REASON_NOT_ASKED = 'notAsked';
    public const string REASON_BREAKER = 'breaker';
    public const string REASON_TRANSPORT = 'transport';
    public const string REASON_UNPARSABLE = 'unparsable';
    public const string REASON_ERROR = 'error';
    public const string REASON_REFUSED = 'refused';
    public const string REASON_SHADOW = 'shadow';

    /** @var string KIND_VERDICT or KIND_UNAVAILABLE. */
    public string $kind = self::KIND_UNAVAILABLE;

    /** @var bool The verdict: true = the text announces the open work instead of delivering it. Meaningful only for a verdict. */
    public bool $wouldBounce = false;

    /** @var string|null The REASON_* of an unavailable outcome; null for a verdict. */
    public ?string $reason = null;

    /** @var AgentTurnClosingDecision|null The stamped decision (the record / slice), when one was written. */
    public ?AgentTurnClosingDecision $decision = null;

    public static function verdict(bool $wouldBounce, ?AgentTurnClosingDecision $decision = null): static
    {
        $outcome = new static();
        $outcome->kind = self::KIND_VERDICT;
        $outcome->wouldBounce = $wouldBounce;
        $outcome->decision = $decision;
        return $outcome;
    }

    public static function unavailable(string $reason, ?AgentTurnClosingDecision $decision = null): static
    {
        $outcome = new static();
        $outcome->kind = self::KIND_UNAVAILABLE;
        $outcome->reason = $reason;
        $outcome->decision = $decision;
        return $outcome;
    }

    public function isVerdict(): bool
    {
        return $this->kind === self::KIND_VERDICT;
    }
}
