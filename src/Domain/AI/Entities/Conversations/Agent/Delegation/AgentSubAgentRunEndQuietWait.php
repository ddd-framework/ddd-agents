<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * The short wait at the head of a turn that sub-agent notifications started: the
 * worker holds the claim and polls until no new run end arrived for {@see QUIET_MILLISECONDS}, or
 * {@see CAP_MILLISECONDS} passed since the wait began, or no sub-agent run is unfinished any more — so run ends close
 * together share ONE model turn. Pure: the caller reads the clock (monotonic milliseconds), the waiting run-end count and
 * the unfinished state at each poll and hands them in; this object answers whether to poll again and records why the
 * wait ended. The polls themselves (the reads, the heartbeat, the pause) are the service's
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService::waitForCloseRunEndsBeforeTheFirstModelCall()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentSubAgentRunEndQuietWait extends ValueObject
{
    /** No new run end for this long ends the wait. */
    public const int QUIET_MILLISECONDS = 2000;

    /** The wait never lasts longer than this from its start. */
    public const int CAP_MILLISECONDS = 8000;

    /** The pause between two polls (like the purpose's bounded background wait). */
    public const int POLL_MICROSECONDS = 250_000;

    /** No run end arrived for {@see QUIET_MILLISECONDS}. */
    public const string ENDED_QUIET = 'quiet';

    /** {@see CAP_MILLISECONDS} passed. */
    public const string ENDED_CAP = 'cap';

    /** No sub-agent run is unfinished: nothing else can arrive (also the answer at the very first poll — no wait at all). */
    public const string ENDED_NO_UNFINISHED_RUN = 'noUnfinishedRun';

    /** The run claim was lost while waiting: the turn's first step boundary ends the run. */
    public const string ENDED_CLAIM_LOST = 'claimLost';

    /** When the wait started (monotonic milliseconds of the caller's clock). */
    public int $startedAtMilliseconds = 0;

    /** When the newest run end was seen (the start counts as one). */
    public int $newestRunEndSeenAtMilliseconds = 0;

    /** How many run-end notifications waited for a model call at the newest poll. */
    public int $waitingRunEndCount = 0;

    /** How many run-end notifications arrived during the wait. */
    public int $runEndsArrivedDuringWait = 0;

    /** When the newest poll ran. */
    public int $newestPollAtMilliseconds = 0;

    /** Why the wait ended (`ENDED_*`); null while it goes on. */
    #[Choice(choices: [self::ENDED_QUIET, self::ENDED_CAP, self::ENDED_NO_UNFINISHED_RUN, self::ENDED_CLAIM_LOST])]
    public ?string $endReason = null;

    /** A wait that starts now with `$waitingRunEndCount` run ends already waiting (those that opened the turn). */
    public static function startAt(int $nowMilliseconds, int $waitingRunEndCount): static
    {
        $agentSubAgentRunEndQuietWait = new static();
        $agentSubAgentRunEndQuietWait->startedAtMilliseconds = $nowMilliseconds;
        $agentSubAgentRunEndQuietWait->newestRunEndSeenAtMilliseconds = $nowMilliseconds;
        $agentSubAgentRunEndQuietWait->newestPollAtMilliseconds = $nowMilliseconds;
        $agentSubAgentRunEndQuietWait->waitingRunEndCount = $waitingRunEndCount;
        return $agentSubAgentRunEndQuietWait;
    }

    /**
     * Take one poll's reading and answer whether to poll again. A higher waiting count is a new run end (the quiet window
     * restarts). Ends — and records why — when no sub-agent run is unfinished, when the cap passed, or when the quiet
     * window passed. A wait that already ended answers false.
     */
    public function continuesAfterPoll(int $nowMilliseconds, int $waitingRunEndCount, bool $hasUnfinishedSubAgentRun): bool
    {
        if ($this->endReason !== null) {
            return false;
        }
        $this->newestPollAtMilliseconds = $nowMilliseconds;
        if ($waitingRunEndCount > $this->waitingRunEndCount) {
            $this->runEndsArrivedDuringWait += $waitingRunEndCount - $this->waitingRunEndCount;
            $this->newestRunEndSeenAtMilliseconds = $nowMilliseconds;
        }
        $this->waitingRunEndCount = max($this->waitingRunEndCount, $waitingRunEndCount);
        if (!$hasUnfinishedSubAgentRun) {
            $this->endReason = self::ENDED_NO_UNFINISHED_RUN;
            return false;
        }
        if ($nowMilliseconds - $this->startedAtMilliseconds >= self::CAP_MILLISECONDS) {
            $this->endReason = self::ENDED_CAP;
            return false;
        }
        if ($nowMilliseconds - $this->newestRunEndSeenAtMilliseconds >= self::QUIET_MILLISECONDS) {
            $this->endReason = self::ENDED_QUIET;
            return false;
        }
        return true;
    }

    /** The run claim was lost between two polls. */
    public function endBecauseTheClaimWasLost(): void
    {
        $this->endReason ??= self::ENDED_CLAIM_LOST;
    }

    /** How long the wait lasted, up to its newest poll. */
    public function getWaitedMilliseconds(): int
    {
        return max(0, $this->newestPollAtMilliseconds - $this->startedAtMilliseconds);
    }

    /** Whether run ends arrived during the wait — the caller reloads the rows the first model call reads. */
    public function hasRunEndsArrivedDuringWait(): bool
    {
        return $this->runEndsArrivedDuringWait > 0;
    }
}
