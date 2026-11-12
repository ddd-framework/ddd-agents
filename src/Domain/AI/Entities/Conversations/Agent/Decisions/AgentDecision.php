<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The part every decision STAMP shares: the owner turn it belongs to, when it was decided,
 * who decided ({@see $decidedBy}: the model under `on` or `shadow`, or a `fallback:*` that names why the model did not
 * decide) and the model version that answered — and the record each stamp writes into the conversation's decision log
 * ({@see buildRecord()}). Whether a stamp is APPLIED stays each subclass's own rule ({@see isApplied()}), and so does
 * its persisted shape (`mapToRepository()` — the key order of every slice is the subclass's).
 *
 * The `DECIDED_BY_*` / `FALLBACK_*` strings are persisted (slices, records, turn events) and read by the decision stats
 * and the admin inspector; every subclass inherits them, so `AgentTurnClosingDecision::FALLBACK_REFUSED` and
 * `AgentReplyLanguageDecision::FALLBACK_BREAKER` name the same strings as the constants here.
 */
abstract class AgentDecision extends ValueObject
{
    public const string DECIDED_BY_DECISION_MODEL = 'jev';
    public const string DECIDED_BY_SHADOW = 'shadow';

    /** The prefix every fallback stamp starts with. */
    public const string FALLBACK_PREFIX = 'fallback:';
    public const string FALLBACK_BREAKER = 'fallback:breaker';
    public const string FALLBACK_TRANSPORT = 'fallback:transport';
    public const string FALLBACK_UNPARSABLE = 'fallback:unparsable';
    /** Our own code failed (a TypeError, a missing registry entry) — logged loudly, never a breaker trip. */
    public const string FALLBACK_ERROR = 'fallback:error';
    /** The budget / envelope / scope gate refused the call — recorded like every other non-verdict. */
    public const string FALLBACK_REFUSED = 'fallback:refused';
    /** The turn's own work between a split call's send and its receive outlasted the decision timeout — never a breaker trip. */
    public const string FALLBACK_OWN_WORK = 'fallback:ownWork';

    /** @var int|null The `indexInConversation` of the owner message this decision belongs to (the per-turn key). */
    public ?int $ownerMessageIndex = null;

    /** @var string|null `Y-m-d H:i:s` when the decision was made. */
    public ?string $decidedAt = null;

    /** @var string|null DECIDED_BY_* / FALLBACK_* — who produced this stamp and whether the loop applies it. */
    public ?string $decidedBy = null;

    /** @var string|null The exact model version that answered. */
    public ?string $modelVersion = null;

    /** True when the loop acts on this stamp — each site's own rule. */
    abstract public function isApplied(): bool;

    /** True when the model did not decide this stamp and {@see $decidedBy} names why (`fallback:*`). */
    public function isFallback(): bool
    {
        return str_starts_with((string)$this->decidedBy, self::FALLBACK_PREFIX);
    }

    /** True when the model's answer was read — applied under `on` ({@see DECIDED_BY_DECISION_MODEL}), recorded only under `shadow`. */
    public function isDecidedByTheModel(): bool
    {
        return in_array($this->decidedBy, [self::DECIDED_BY_DECISION_MODEL, self::DECIDED_BY_SHADOW], true);
    }

    /**
     * This stamp's record for the conversation's decision log: the site, this stamp's owner message, who decided and
     * the model version, beside what the call sent and got back and the consequence the site resolved.
     *
     * @param array<string, mixed>|null $state the state as sent (long fields are cut in the record)
     * @param array<string, mixed>|null $answers the raw answers object
     * @param array<string, mixed> $outcome the resolved consequence
     * @param array<string, mixed>|null $questions the questions as sent
     */
    public function buildRecord(
        string $site,
        bool $applied,
        ?int $elapsedMs,
        ?string $failureReason,
        ?array $state,
        ?array $answers,
        array $outcome,
        ?array $questions = null
    ): AgentDecisionRecord {
        return AgentDecisionRecord::build(
            site: $site,
            ownerMessageIndex: $this->ownerMessageIndex,
            decidedBy: $this->decidedBy,
            applied: $applied,
            elapsedMs: $elapsedMs,
            modelVersion: $this->modelVersion,
            failureReason: $failureReason,
            state: $state,
            answers: $answers,
            outcome: $outcome,
            questions: $questions
        );
    }
}
