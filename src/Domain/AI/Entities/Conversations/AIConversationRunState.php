<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The async run-state of an {@see AIConversation} (parallel delegations). String constants
 * (not a native enum) to match the project's `AIModel::*` / {@see \DDD\Domain\AI\Entities\Models\Selection\ModelTier}
 * style and to key the run-claim UPDATE / reaper query directly.
 *
 * The run-claim (`\DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::claimRun`) is the single primitive that flips `IDLE`/stale → `RUNNING`;
 * `AWAITING_DELEGATES` is a parked parent holding NO claim while its delegation children run (each child claims only
 * its own conversation); `FAILED` is a turn whose retry cap is exhausted (the reaper/admin must SEE it, never silently
 * re-run). `CANCELLED` is reserved for the Phase-4 step-boundary cancel.
 *
 * The instance form answers the run-state questions callers used to write as their
 * own state lists: {@see of()} wraps one stored value (also one read from a raw row), {@see AIConversation::getRunState()}
 * the conversation's. Each question keeps the members its callers wrote; two questions with different members stay two
 * questions until they are merged deliberately. The constants stay for column values and SQL binds.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunState extends ValueObject
{
    /** Not running; claimable. The resting state, and what a finished turn releases to. */
    public const string IDLE = 'IDLE';

    /** A worker holds the run-claim and is driving a turn (heartbeat-touched while alive). */
    public const string RUNNING = 'RUNNING';

    /** Parent parked while its delegation children run — holds NO claim; woken by each child's fan-in resume. */
    public const string AWAITING_DELEGATES = 'AWAITING_DELEGATES';

    /** Retry cap (`resumeAttempts`) exhausted — terminal, surfaced to the admin; never auto-re-run. Also the terminal a delegation child takes when it errors/times out. */
    public const string FAILED = 'FAILED';

    /**
     * A delegation child that produced its verdict (terminal success). Symmetric with the message-level
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::STATUS_COMPLETED}. The fork-join
     * settle counts `runState = 'COMPLETED'` members; distinct from `IDLE` (a root at rest / a child not yet claimed).
     */
    public const string COMPLETED = 'COMPLETED';

    /** Reserved: a running turn cancelled at a step boundary (e.g. superseded by a new user message). */
    public const string CANCELLED = 'CANCELLED';

    /**
     * Concurrency cap: a delegation child CREATED + seeded but NOT yet dispatched — it waits for an in-flight
     * slot under its tree (global cap, {@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP}).
     * The scheduler promotes it (QUEUED → IDLE → dispatch) as a sibling settles and frees a slot. Not claimable by a
     * worker until promoted; the parent stays AWAITING_DELEGATES because its delegate-call is PENDING.
     */
    public const string QUEUED = 'QUEUED';

    /**
     * States from which the reaper may reclaim a stale row (non-terminal, in-flight). QUEUED is included so
     * a delegation child starved over the concurrency cap — never promoted because its sibling-settle promote was
     * dropped — is reaper-visible (a batch timeout force-fails it). Terminal states (IDLE-at-rest, COMPLETED, FAILED,
     * CANCELLED) are excluded.
     */
    public const array NON_TERMINAL = [self::RUNNING, self::AWAITING_DELEGATES, self::QUEUED];

    /** @var string[] The states a finished conversation rests in — a delegation child in one of them is never re-claimed by a machine resume. */
    public const array TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELLED];

    /** In-flight delegation WORKERS for the concurrency cap — a RUNNING child is one active worker (parked/queued are not). */
    public const array ACTIVE_DELEGATION_WORKER = [self::RUNNING];

    /** @var string[] A conversation that is over for good: completed or cancelled (a FAILED one may be retried). */
    public const array CLOSED = [self::COMPLETED, self::CANCELLED];

    /** @var string[] A turn is open on the conversation itself: a worker drives it, or it waits for its delegation children. */
    public const array LIVE_TURN = [self::RUNNING, self::AWAITING_DELEGATES];

    /** @var string[] No worker holds the conversation and it may run again: at rest, or failed and retryable. */
    public const array AT_REST_OR_FAILED = [self::IDLE, self::FAILED];

    /** @var string[] No worker holds the conversation and none is waiting to: at rest or in any end state. */
    public const array AT_REST_OR_ENDED = [self::IDLE, self::FAILED, self::COMPLETED, self::CANCELLED];

    /** @var string[] Not ended: at rest, or a run in flight (driven, waiting for its children, or queued). */
    public const array AT_REST_OR_IN_FLIGHT = [self::IDLE, self::AWAITING_DELEGATES, self::RUNNING, self::QUEUED];

    /** @var string[] At rest, or waiting for its delegation children without a worker. */
    public const array AT_REST_OR_AWAITING_DELEGATES = [self::IDLE, self::AWAITING_DELEGATES];

    /** @var string[] A worker drives the conversation or it waits in the queue for a worker slot. */
    public const array RUNNING_OR_QUEUED = [self::RUNNING, self::QUEUED];

    /** @var string[] A delegation child no worker has started yet: queued behind the cap, or promoted and not yet claimed. */
    public const array DELEGATION_CHILD_NOT_STARTED = [self::QUEUED, self::IDLE];

    /** @var string|null The stored run-state value (one of the constants above; a raw row may carry anything or null). */
    public ?string $stateName = null;

    /** The run state of one stored value — a conversation's column or a raw row's; reads nothing. */
    public static function of(?string $stateName): static
    {
        $aIConversationRunState = new static();
        $aIConversationRunState->stateName = $stateName;
        return $aIConversationRunState;
    }

    /** {@see CLOSED}: COMPLETED or CANCELLED. */
    public function isClosed(): bool
    {
        return in_array($this->stateName, self::CLOSED, true);
    }

    /** {@see NON_TERMINAL}: RUNNING, AWAITING_DELEGATES or QUEUED. */
    public function isNonTerminal(): bool
    {
        return in_array($this->stateName, self::NON_TERMINAL, true);
    }

    /** {@see TERMINAL}: COMPLETED, FAILED or CANCELLED. */
    public function isTerminal(): bool
    {
        return in_array($this->stateName, self::TERMINAL, true);
    }

    /** {@see LIVE_TURN}: RUNNING or AWAITING_DELEGATES. */
    public function holdsALiveTurn(): bool
    {
        return in_array($this->stateName, self::LIVE_TURN, true);
    }

    /** {@see AT_REST_OR_FAILED}: IDLE or FAILED. */
    public function isAtRestOrFailed(): bool
    {
        return in_array($this->stateName, self::AT_REST_OR_FAILED, true);
    }

    /** {@see AT_REST_OR_ENDED}: IDLE, FAILED, COMPLETED or CANCELLED. */
    public function isAtRestOrEnded(): bool
    {
        return in_array($this->stateName, self::AT_REST_OR_ENDED, true);
    }

    /** {@see AT_REST_OR_IN_FLIGHT}: IDLE, AWAITING_DELEGATES, RUNNING or QUEUED. */
    public function isAtRestOrInFlight(): bool
    {
        return in_array($this->stateName, self::AT_REST_OR_IN_FLIGHT, true);
    }

    /** {@see AT_REST_OR_AWAITING_DELEGATES}: IDLE or AWAITING_DELEGATES. */
    public function isAtRestOrAwaitingDelegates(): bool
    {
        return in_array($this->stateName, self::AT_REST_OR_AWAITING_DELEGATES, true);
    }

    /** {@see RUNNING_OR_QUEUED}: RUNNING or QUEUED. */
    public function isRunningOrQueued(): bool
    {
        return in_array($this->stateName, self::RUNNING_OR_QUEUED, true);
    }
}
