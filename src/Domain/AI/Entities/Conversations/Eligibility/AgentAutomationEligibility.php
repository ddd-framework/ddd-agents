<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Eligibility;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Whether the platform may run AUTOMATED AI work for an account or an agent space right now — the picture the core reads
 * before a schedule fires, a worker runs, a report is delivered or a tool executes. The application builds it
 * ({@see \DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService}); without an application
 * rule every scope is eligible.
 *
 * The STATE names no cause; {@see $reason} and {@see $signalSource} do (opaque strings the application defines). An
 * application's eligibility object extends this class and adds its reasons, its signal sources and its resolution rule.
 *
 * @phpstan-consistent-constructor
 */
class AgentAutomationEligibility extends ValueObject
{
    /** Automated AI work runs; the budget reads the real remaining amount. */
    public const string STATE_ELIGIBLE = 'ELIGIBLE';

    /** Nothing runs; resumes by itself when the lock is lifted. */
    public const string STATE_SUSPENDED = 'SUSPENDED';

    /** Nothing runs; only an explicit reactivation brings it back. */
    public const string STATE_ENDED = 'ENDED';

    /** Nothing runs, ever — the account or the agent space was erased under a data-protection request. */
    public const string STATE_ERASED = 'ERASED';

    /** The signal source of a decision about the agent space — {@see getAutomationStateReason()} stamps a space reason for it. */
    public const string SIGNAL_SOURCE_AGENT_SPACE = 'AGENT_SPACE';

    /**
     * @var string[] The signal sources whose decision is about the agent space, not the account; an application lists
     * its own sources here (what the space stands for in it, a pending deletion of that).
     */
    public const array AGENT_SPACE_DECIDING_SIGNAL_SOURCES = [self::SIGNAL_SOURCE_AGENT_SPACE];

    /** @var string[] The reasons that mean the scope is gone for good besides the erased state; an application adds its own. */
    public const array REASONS_OF_A_SCOPE_GONE_FOR_GOOD = [];

    /** @var array<string, int> Severity ranking — the most terminal signal decides. */
    protected const array STATE_SEVERITY = [
        self::STATE_ELIGIBLE => 0,
        self::STATE_SUSPENDED => 1,
        self::STATE_ENDED => 2,
        self::STATE_ERASED => 3,
    ];

    /** @var string One of the STATE_* constants. */
    public string $state = self::STATE_ELIGIBLE;

    /** @var string|null The application's reason; null when eligible. */
    public ?string $reason = null;

    /** @var string|null Which source decided (opaque, application-defined); null when eligible. */
    public ?string $signalSource = null;

    /** @var bool Whether the state clears by itself (a lifted lock) — false for ENDED / ERASED. */
    public bool $resumesAutomatically = false;

    /** @var DateTime|null When the deciding state began, where a source records it; null when unknown. */
    public ?DateTime $since = null;

    /** @var int|null The account that was evaluated (the driving account, when known). */
    public ?int $accountId = null;

    /** @var int|null The effective tenant — the parent account when one exists; null when the application has none. */
    public ?int $tenantAccountId = null;

    /** @return bool */
    public function isEligible(): bool
    {
        return $this->state === self::STATE_ELIGIBLE;
    }

    /** @return bool */
    public function isSuspended(): bool
    {
        return $this->state === self::STATE_SUSPENDED;
    }

    /** @return bool */
    public function isEnded(): bool
    {
        return $this->state === self::STATE_ENDED;
    }

    /** @return bool */
    public function isErased(): bool
    {
        return $this->state === self::STATE_ERASED;
    }

    /**
     * Whether the scope is gone for good: erased, or one of {@see REASONS_OF_A_SCOPE_GONE_FOR_GOOD}. Every other ended
     * state can come back. Pure.
     *
     * @return bool
     */
    public function isScopeGoneForGood(): bool
    {
        return $this->isErased() || in_array($this->reason, static::REASONS_OF_A_SCOPE_GONE_FOR_GOOD, true);
    }

    /**
     * The reason the platform STAMPS on a schedule it closes or a run it suppresses — a value of the application's
     * reason class ({@see AgentAutomationStateReason::getReasonClassOfTheApplication()}): about the agent space when
     * one of its signal sources decided ({@see AGENT_SPACE_DECIDING_SIGNAL_SOURCES}), about the account otherwise.
     * Null when eligible (nothing to stamp).
     *
     * @return string|null
     */
    public function getAutomationStateReason(): ?string
    {
        if ($this->isEligible()) {
            return null;
        }
        $reasonClassOfTheApplication = AgentAutomationStateReason::getReasonClassOfTheApplication();
        $agentSpaceDecided = in_array($this->signalSource, static::AGENT_SPACE_DECIDING_SIGNAL_SOURCES, true);
        if ($this->isErased()) {
            // an erased space belongs to an erased account family in practice, and the schedule vocabulary has one
            // erased marker: nothing ever resumes either way
            return $agentSpaceDecided ? $reasonClassOfTheApplication::AGENT_SPACE_ENDED : $reasonClassOfTheApplication::ACCOUNT_ERASED;
        }
        if ($this->isSuspended()) {
            return $agentSpaceDecided ? $reasonClassOfTheApplication::AGENT_SPACE_SUSPENDED : $reasonClassOfTheApplication::ACCOUNT_SUSPENDED;
        }
        return $agentSpaceDecided ? $reasonClassOfTheApplication::AGENT_SPACE_ENDED : $reasonClassOfTheApplication::ACCOUNT_ENDED;
    }

    /**
     * The whole picture as plain fields for an inspection read (a console command prints it as JSON, an admin tool
     * returns it). An application's picture adds the fields it evaluates below the account.
     *
     * @return array<string, mixed>
     */
    public function buildInspectionFields(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'signalSource' => $this->signalSource,
            'resumesAutomatically' => $this->resumesAutomatically,
            'since' => $this->since?->format('Y-m-d H:i:s'),
            'accountId' => $this->accountId,
            'tenantAccountId' => $this->tenantAccountId,
            'automationStateReason' => $this->getAutomationStateReason(),
        ];
    }

    /**
     * The deterministic, model-facing sentence for a non-eligible scope — rendered first by the budget value objects so a
     * non-eligible account is never told to use the AI features with confidence. Empty when eligible. The entity
     * next to the account is named with the application's word ({@see AgentApplicationWording::$billingEntityName}).
     *
     * @return string
     */
    public function describeForModel(): string
    {
        $billingEntityName = AgentApplicationWording::current()->billingEntityName;
        return match ($this->state) {
            self::STATE_ERASED => 'This account was erased under a data-protection request: no AI operation may run for it, ever. '
                . 'Do not attempt any AI-powered action.',
            self::STATE_ENDED => 'This account or ' . $billingEntityName . ' is no longer active (' . strtolower(str_replace('_', ' ', (string)$this->reason)) . '): '
                . 'automated AI work is stopped and AI-powered operations are not funded until it is explicitly reactivated. '
                . 'Do not quote a remaining budget.',
            self::STATE_SUSPENDED => 'This account or ' . $billingEntityName . ' is currently locked (' . strtolower(str_replace('_', ' ', (string)$this->reason)) . '): '
                . 'automated AI work is paused and resumes by itself once the lock is lifted. Do not quote a remaining budget.',
            default => '',
        };
    }

    /**
     * The eligible picture for a scope — the only state that carries no reason. Built as the called class, so an
     * application subclass gets its own type.
     *
     * @param int|null $accountId
     * @param int|null $tenantAccountId
     * @return self
     */
    public static function eligible(?int $accountId = null, ?int $tenantAccountId = null): self
    {
        $eligibility = new static();
        $eligibility->accountId = $accountId;
        $eligibility->tenantAccountId = $tenantAccountId;
        return $eligibility;
    }
}
