<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one owner-consent decision produced ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::decideDialogue()}):
 * the verdict, the dialogue it judged and how the decision-model call went — the last two for the turn event; both null
 * when the check ended before them (a delegation child, the site mode, no live owner, no owner row).
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentDecisionResult extends ValueObject
{
    public OwnerConsentVerdict $verdict;

    /** The dialogue the decision read; null when the check ended before it was built. */
    public ?OwnerConsentDialogue $dialogue = null;

    /** How the decision-model call went; null when no call was run. */
    public ?AgentDecisionCallOutcome $callOutcome = null;

    public static function create(OwnerConsentVerdict $verdict, ?OwnerConsentDialogue $dialogue = null, ?AgentDecisionCallOutcome $callOutcome = null): static
    {
        $ownerConsentDecisionResult = new static();
        $ownerConsentDecisionResult->verdict = $verdict;
        $ownerConsentDecisionResult->dialogue = $dialogue;
        $ownerConsentDecisionResult->callOutcome = $callOutcome;
        return $ownerConsentDecisionResult;
    }
}
