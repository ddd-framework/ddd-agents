<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The VERDICT of the server-side mandate approval check:
 * given the requested mandate (action class + scope) and the server-pulled dialogue window, did the CUSTOMER
 * actually grant this? Inputs are set by {@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService} before the
 * Argus load; outputs come from the LLM check ({@see \DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval}).
 * The dialogue window is NEVER supplied by the dialog model — it is pulled from the conversation's own messages
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::buildUserVisibleDialogueExcerptEndingAtMessage()}),
 * so a grant cannot be forged by tool arguments.
 */
class AgentMandateApproval extends ValueObject
{
    /** The one verdict code of an approval. */
    public const string VERDICT_CONSENT_FOUND = 'CONSENT_FOUND';

    public const string VERDICT_NO_CONSENT = 'NO_CONSENT';
    public const string VERDICT_DIFFERENT_ACTION_OR_SCOPE = 'DIFFERENT_ACTION_OR_SCOPE';
    public const string VERDICT_QUANTITY_BASED = 'QUANTITY_BASED';
    public const string VERDICT_CONDITIONAL_OR_WITHDRAWN = 'CONDITIONAL_OR_WITHDRAWN';
    public const string VERDICT_STEERING_TEXT = 'STEERING_TEXT';

    /**
     * The verdict codes of a rejection. A code is the structural proof that the check judged the dialogue: a response
     * without one (or with one that contradicts `approved`) is no verdict and never becomes a disapproval reason.
     */
    public const array REJECTION_VERDICT_CODES = [
        self::VERDICT_NO_CONSENT,
        self::VERDICT_DIFFERENT_ACTION_OR_SCOPE,
        self::VERDICT_QUANTITY_BASED,
        self::VERDICT_CONDITIONAL_OR_WITHDRAWN,
        self::VERDICT_STEERING_TEXT,
    ];

    // ---- inputs (set before the check runs) ----

    /** @var string|null The ACTION_* class the agent asks to be mandated for. */
    public ?string $requestedActionClass = null;

    /** @var string|null Plain-language description of what the action class covers — so the check judges the right scope. */
    public ?string $requestedActionClassDescription = null;

    /** @var string|null The requested SCOPE_* type (ONE_INSTRUCTION / TIME_WINDOW / UNLIMITED). */
    public ?string $requestedScopeType = null;

    /** @var DateTime|null The requested window end (TIME_WINDOW only). */
    public ?DateTime $requestedValidUntil = null;

    /** @var string|null The agent's claimed constraints ("nur positive Bewertungen") — verified against the dialogue. */
    public ?string $requestedConstraintsNote = null;

    /** @var string|null The server-pulled dialogue window ("Customer:"/"Agent:" lines, chronological). */
    public ?string $dialogueExcerptSource = null;

    // ---- outputs (filled by the LLM check) ----

    public ?bool $approved = null;

    /** @var string|null The VERDICT_* code the check named — set only when the response was a verdict. */
    public ?string $verdictCode = null;

    /**
     * @var string|null On approval: the exact markdown summary of the granting dialogue with VERBATIM customer
     * quotes in the conversation's language — becomes {@see AgentMandate::$customerApprovalExcerpt}.
     */
    public ?string $customerApprovalExcerpt = null;

    /**
     * @var string|null On disapproval: the model-facing reason, actionable for the agent's next turn — e.g.
     * "We cannot establish a counter for automatic review replies; offer a time-limited or an unlimited mandate."
     */
    public ?string $disapprovalReason = null;

    /** @var string|null The constraints the CUSTOMER actually stated (verified/refined from the dialogue). */
    public ?string $constraintsNote = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(md5(
            ($this->requestedActionClass ?? '') . '|' . ($this->requestedScopeType ?? '')
            . '|' . md5($this->dialogueExcerptSource ?? '')
        ));
    }
}
