<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecision;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The owner-consent check's answer for one step. FAIL-CLOSED: every failure — the site off (there
 * is no deterministic fallback after the switch), the breaker, a transport failure, an unparsable answer, an error, no
 * eligible owner row, no live owner — is NOT a consent. Only a call without agent provenance (HTTP MCP, the harness's
 * direct calls) is `ungated` and passes. Renders the text the model reads when the step is not done.
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentVerdict extends ValueObject
{
    public const string DECIDED_BY_DECISION_MODEL = 'jev';
    public const string DECIDED_BY_SHADOW = 'shadow';
    public const string DECIDED_BY_UNGATED = 'ungated';

    public const string FAILURE_SITE_OFF = 'siteOff';
    public const string FAILURE_NO_OWNER_ROW = 'noOwnerRow';
    public const string FAILURE_NO_LIVE_OWNER = 'noLiveOwner';
    public const string FAILURE_DELEGATION_CHILD = 'delegationChild';
    public const string FAILURE_BREAKER = 'breaker';
    public const string FAILURE_TRANSPORT = 'transport';
    public const string FAILURE_REFUSED = 'refused';
    public const string FAILURE_UNPARSABLE = 'unparsable';
    public const string FAILURE_ERROR = 'error';

    /** @var string[] The failures that mean "the confirmation could not be checked" — not "the owner did not agree". */
    public const array UNCHECKABLE_FAILURES = [self::FAILURE_SITE_OFF, self::FAILURE_BREAKER, self::FAILURE_TRANSPORT, self::FAILURE_REFUSED, self::FAILURE_UNPARSABLE, self::FAILURE_ERROR];

    /** Whether the step may run NOW — only a binding (`jev`) or `ungated` verdict; a `shadow` verdict never consents. */
    public bool $consented = false;

    /** Whether the decision model's answer reached the threshold — under `shadow` too (recorded, never acted on). */
    public bool $wouldConsent = false;

    /** The owner row the consent is recorded on (the dialogue's newest owner row); null when none was read. */
    public ?int $consentingOwnerMessageIndex = null;

    public ?float $stepExplainedProbability = null;

    public ?float $ownerAgreedProbability = null;

    /** One of the FAILURE_* constants, null when the decision model answered. */
    public ?string $failure = null;

    /** One of the DECIDED_BY_* constants, null when nothing decided (a failure). */
    public ?string $decidedBy = null;

    /** Passes without a check: no agent provenance (HTTP MCP, the harness's direct calls). */
    public static function ungated(): static
    {
        $verdict = new static();
        $verdict->consented = true;
        $verdict->wouldConsent = true;
        $verdict->decidedBy = self::DECIDED_BY_UNGATED;
        return $verdict;
    }

    /** Not a consent, for the given failure; the owner row the dialogue offered, when there was one. */
    public static function failed(string $failure, ?int $ownerMessageIndex = null): static
    {
        $verdict = new static();
        $verdict->failure = $failure;
        $verdict->consentingOwnerMessageIndex = $ownerMessageIndex;
        return $verdict;
    }

    /**
     * The decision model's answer: it reaches the threshold only when BOTH probabilities do; it CONSENTS only when it is
     * binding (`jev`) — a `shadow` verdict is recorded as {@see $wouldConsent} and never lets the step run.
     */
    public static function decided(OwnerConsentRequest $request, float $stepExplainedProbability, float $ownerAgreedProbability, ?int $ownerMessageIndex, string $decidedBy): static
    {
        $verdict = new static();
        $verdict->stepExplainedProbability = $stepExplainedProbability;
        $verdict->ownerAgreedProbability = $ownerAgreedProbability;
        $verdict->consentingOwnerMessageIndex = $ownerMessageIndex;
        $verdict->decidedBy = $decidedBy;
        $verdict->wouldConsent = $stepExplainedProbability >= $request->minimumProbability
            && $ownerAgreedProbability >= $request->minimumProbability;
        $verdict->consented = $verdict->wouldConsent && $decidedBy === self::DECIDED_BY_DECISION_MODEL;
        return $verdict;
    }

    /**
     * The `decidedBy` stamp of this verdict on the decision log (the turn event) — the one stamp rule of every decision
     * site: who decided (`jev`, `shadow`, `ungated`), else the
     * `fallback:*` of a decision-model call that brought no usable answer (breaker, our own gate's refusal, transport,
     * unparsable, our own error); null when no call was made for the step (the site off, no owner row, no live owner,
     * a delegation child). The verdict itself keeps its FAILURE_* vocabulary.
     */
    public function getDecisionStamp(): ?string
    {
        return $this->decidedBy ?? match ($this->failure) {
            self::FAILURE_BREAKER => AgentDecision::FALLBACK_BREAKER,
            self::FAILURE_REFUSED => AgentDecision::FALLBACK_REFUSED,
            self::FAILURE_TRANSPORT => AgentDecision::FALLBACK_TRANSPORT,
            self::FAILURE_UNPARSABLE => AgentDecision::FALLBACK_UNPARSABLE,
            self::FAILURE_ERROR => AgentDecision::FALLBACK_ERROR,
            default => null,
        };
    }

    /** Whether the confirmation could not be checked (the site off, the model unavailable) — the "call again once" text. */
    public function isUncheckable(): bool
    {
        return $this->failure !== null && in_array($this->failure, self::UNCHECKABLE_FAILURES, true);
    }

    /** The text the model reads when the step is NOT done; '' for a consent. */
    public function buildModelFacingText(OwnerConsentRequest $request): string
    {
        if ($this->consented) {
            return '';
        }
        if ($this->failure === self::FAILURE_DELEGATION_CHILD) {
            return 'NOT DONE — this step needs the owner\'s explicit confirmation, which only the main conversation can obtain. '
                . 'Report back that the owner must confirm: ' . $request->targetLine . '. Do not retry.';
        }
        if ($this->failure === self::FAILURE_NO_LIVE_OWNER) {
            return 'NOT DONE — this step needs the owner\'s explicit confirmation, and no owner takes part in this run. Report '
                . 'that the owner must confirm: ' . $request->targetLine . '. Do not retry.';
        }
        if ($this->isUncheckable()) {
            return 'NOT DONE — nothing was changed. The owner\'s confirmation could not be checked right now. Call this tool '
                . 'again once; if it fails again, tell the owner the step could not be done right now.';
        }
        return 'NOT DONE — nothing was changed. This step needs the owner\'s explicit confirmation. Ask the owner explicitly '
            . 'about exactly this step — ' . $request->targetLine . ' — and tell them the consequence: ' . $request->consequenceLine
            . '. A decision model evaluates the owner\'s answer; call this tool again with the SAME arguments only AFTER the '
            . 'owner confirmed.';
    }
}
