<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * How a worker turn hands its run claim back: the whole decision of one
 * release branch of {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady()}
 * — the stall give-up reply, the turn-end event, the run state, the attempt count and the handover marker of the
 * token-guarded release, the schedule's run-terminal record, the realtime status, the waiting-signal probe, the async
 * re-fire, the promotion of queued children and the settled-turn follow-ups — or, for a delegation child that finishes
 * into its batch, that the child's terminal owns the release. Pure: the clock and the derives are inputs, nothing is
 * written here; the agent service writes the child terminal and applies the rest in today's order
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::applyRunRelease()}). The delegation
 * inputs are named for what they mean to the release ("unfinished delegation work", "children's answers unread"), not
 * for the batch model that answers them today.
 *
 * @phpstan-consistent-constructor
 */
class AgentRunRelease extends ValueObject
{
    /** No waiting-signal probe after the release. */
    public const string WAITING_SIGNAL_PROBE_NONE = 'NONE';

    /** The probe runs after the release and BEFORE the publish: a waiting signal makes the status RUNNING and re-fires. */
    public const string WAITING_SIGNAL_PROBE_BEFORE_PUBLISH = 'BEFORE_PUBLISH';

    /** The probe runs after the publish: a waiting signal only re-fires (the status is RUNNING already). */
    public const string WAITING_SIGNAL_PROBE_AFTER_PUBLISH = 'AFTER_PUBLISH';

    /** Retries a turn gets before its run ends FAILED. */
    public const int MAX_RESUME_ATTEMPTS = 3;

    /** @var string|null The run state the release writes; null = this boundary writes no release (the child terminal owns it). */
    public ?string $runState = null;

    /** @var int|null The attempt count the release writes; null = unchanged. */
    public ?int $resumeAttempts = null;

    /** @var DateTime|null The handover marker the release stamps (the owed-turn recovery pass selects on it); null = none. */
    public ?DateTime $agentTurnOwedAt = null;

    /** @var bool Whether the attempt count passed {@see MAX_RESUME_ATTEMPTS} (a failure release only). */
    public bool $overCap = false;

    /** @var string|null The turn-end event recorded BEFORE the release (an `AIConversationTurnEvent::OUTCOME_*`); null = none. */
    public ?string $turnEndOutcome = null;

    /** @var bool Whether the turn-end event is recorded late (the turn was answered by an earlier chunk). */
    public bool $turnEndRecordedLate = false;

    /** @var bool|null The schedule's run-terminal record after the release (true = a completed firing); null = none. */
    public ?bool $scheduleRunCompleted = null;

    /** @var string|null The realtime status published after the release (`AIConversationRealtimeSignalService::STATUS_*`); null = none. */
    public ?string $realtimeStatus = null;

    /** @var bool Whether this delegation child finishes into its batch: its terminal writes the child's run state, not this release. */
    public bool $finishesIntoDelegationBatch = false;

    /** @var string|null The realtime status when the child yielded to an owner input accepted after its last look; null = none. */
    public ?string $realtimeStatusWhenChildYielded = null;

    /** @var bool Whether the stall give-up reply (owed → report → delivered) is appended BEFORE the event and the release. */
    public bool $appendsStallGiveUpReply = false;

    /**
     * @var string Whether and where the lost-wakeup probe runs (`WAITING_SIGNAL_PROBE_*`): always AFTER the release —
     * a signal injected while the claim was held dispatched no resume, and probed before the release it would be lost.
     */
    public string $waitingSignalProbe = self::WAITING_SIGNAL_PROBE_NONE;

    /** @var int|null The message index above which the probe looks for a waiting signal. */
    public ?int $waitingSignalProbeAboveIndex = null;

    /** @var string|null The branch the probe's trace names when it found a signal; null = no trace. */
    public ?string $waitingSignalProbeTraceBranch = null;

    /** @var bool Whether the turn re-fires one async resume after the publish (work is owed). */
    public bool $reFiresAfterRelease = false;

    /** @var bool Whether the tree's queued delegation children are promoted after the re-fire. */
    public bool $promotesQueuedDelegations = false;

    /**
     * @var bool Whether the follow-ups of a finished turn run when nothing re-fired (schedule completed, memory,
     * supervisor, purpose close, the application's own follow-up).
     */
    public bool $runsSettledTurnFollowUps = false;

    /**
     * A throw BEFORE the drive (space, cover, messages load, steer materialization, derive): one more attempt.
     * Under the cap the run goes IDLE with a fresh handover marker (the claim cleared it) and reports RUNNING — the
     * turn is still owed; over it the run ends FAILED (no marker), or a delegation child in a batch finishes into it.
     */
    public static function afterPreDriveFailure(int $resumeAttemptsBeforeTurn, bool $isDelegationChildInBatch, DateTime $now): self
    {
        $runRelease = self::countingOneMoreAttempt($resumeAttemptsBeforeTurn);
        if ($runRelease->overCap && $isDelegationChildInBatch) {
            return $runRelease->finishingIntoDelegationBatch(AIConversationRealtimeSignalService::STATUS_RUNNING);
        }
        if ($runRelease->overCap) {
            return $runRelease->endingFailed(withScheduleRecord: true);
        }
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->agentTurnOwedAt = $now;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
        return $runRelease;
    }

    /**
     * The pre-drive failure's child terminal threw: the run ends FAILED with the counted attempts after the turn-end
     * event — no schedule record, no marker.
     */
    public function afterDelegationChildTerminalThrew(): self
    {
        $runRelease = self::countingAttempts($this->resumeAttempts ?? 0);
        return $runRelease->endingFailed(withScheduleRecord: false);
    }

    /**
     * The account's AI budget is used up: deterministic until it resets, so FAILED with attempts 0 (no retry), or a
     * delegation child in a batch finishes into it (nothing published when it yielded).
     */
    public static function afterBudgetExhausted(bool $isDelegationChildInBatch): self
    {
        return self::afterDeterministicFailure($isDelegationChildInBatch);
    }

    /**
     * The conversation's agent space or account no longer permits automated work: deterministic
     * until the scope changes, so no retry. A scope gone for good (erased, the agent space deleted) closes the run CANCELLED
     * without a failed widget; every scope that can return (suspended, a lapsed subscription, a pending deletion) fails
     * like a budget stop, so a new message re-arms it; a delegation child in a batch finishes into it so the parent is
     * not stranded.
     */
    public static function afterScopeNotEligible(bool $scopeGoneForGood, bool $isDelegationChildInBatch): self
    {
        if (!$scopeGoneForGood || $isDelegationChildInBatch) {
            return self::afterDeterministicFailure($isDelegationChildInBatch);
        }
        $runRelease = self::countingAttempts(0);
        $runRelease->runState = AIConversationRunState::CANCELLED;
        // the turn end is recorded where the turn's tail is loaded (the mid-turn stop; the resume head skips it) and an
        // open chat stops its running indicator — no failed widget
        $runRelease->turnEndOutcome = AIConversationTurnEvent::OUTCOME_CANCELLED;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        return $runRelease;
    }

    /** The model call returned a provider error: the same release as {@see afterBudgetExhausted()}. */
    public static function afterProviderError(bool $isDelegationChildInBatch): self
    {
        return self::afterDeterministicFailure($isDelegationChildInBatch);
    }

    /**
     * The message-index lock stayed unavailable (lever E, transient): one more attempt; under the cap IDLE with a
     * fresh handover marker and realtime IDLE, over it FAILED (no marker) — or a delegation child in a batch finishes
     * into it (nothing published when it yielded).
     */
    public static function afterIndexLockUnavailable(int $resumeAttemptsBeforeTurn, bool $isDelegationChildInBatch, DateTime $now): self
    {
        $runRelease = self::countingOneMoreAttempt($resumeAttemptsBeforeTurn);
        if ($runRelease->overCap && $isDelegationChildInBatch) {
            return $runRelease->finishingIntoDelegationBatch(null);
        }
        if ($runRelease->overCap) {
            return $runRelease->endingFailed(withScheduleRecord: true);
        }
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->agentTurnOwedAt = $now;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        return $runRelease;
    }

    /**
     * Any other turn error (rethrown by the caller), thrown after the turn's first heartbeat: one more attempt counts,
     * whether or not the turn appended rows before it threw. Under the cap the run goes IDLE with a fresh handover
     * marker and reports RUNNING — the owner's message is still owed and the owed-turn recovery pass re-drives it after
     * its quiet window; over the cap the run ends FAILED (no marker, the owner sees the failed widget and its Retry),
     * or a delegation child in a batch finishes into it (RUNNING when it yielded).
     *
     * The attempt counts even after real progress: the recovery pass re-drives every turn this release leaves owed, so
     * a turn that appends a tool row and then throws the same way every time would otherwise be re-driven forever with
     * its count reset by each run.
     */
    public static function afterTurnError(int $resumeAttemptsBeforeTurn, bool $isDelegationChildInBatch, DateTime $now): self
    {
        $runRelease = self::countingOneMoreAttempt($resumeAttemptsBeforeTurn);
        if ($runRelease->overCap && $isDelegationChildInBatch) {
            return $runRelease->finishingIntoDelegationBatch(AIConversationRealtimeSignalService::STATUS_RUNNING);
        }
        if ($runRelease->overCap) {
            return $runRelease->endingFailed(withScheduleRecord: true);
        }
        return $runRelease->owingTheTurnAgain($now);
    }

    /**
     * The model call's HTTP transfer failed (a connect or read timeout, a dropped connection, a Guzzle response
     * exception — Guzzle's `TransferException`, rewrapped as {@see \DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException}): transient
     * like the index lock, so the same release — one more attempt; under the cap IDLE with a fresh handover marker, over
     * it FAILED (no marker), or a delegation child in a batch finishes into it (nothing published when it yielded). The
     * status under the cap is RUNNING, not the index lock's IDLE: the owner's message is unanswered and the recovery
     * pass will answer it.
     */
    public static function afterModelCallTransportFailure(int $resumeAttemptsBeforeTurn, bool $isDelegationChildInBatch, DateTime $now): self
    {
        $runRelease = self::countingOneMoreAttempt($resumeAttemptsBeforeTurn);
        if ($runRelease->overCap && $isDelegationChildInBatch) {
            return $runRelease->finishingIntoDelegationBatch(null);
        }
        if ($runRelease->overCap) {
            return $runRelease->endingFailed(withScheduleRecord: true);
        }
        return $runRelease->owingTheTurnAgain($now);
    }

    /**
     * The supervisor tandem ran its checkpoint (it never parks, never delegates): IDLE with attempts 0, realtime IDLE;
     * the caller re-fires once after the release when a beat is still owed.
     */
    public static function afterSupervisorTandemTurn(): self
    {
        $runRelease = self::countingAttempts(0);
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        return $runRelease;
    }

    /**
     * Nothing was owed at the first derive; the reload re-derived. Owed work now (an inbound or children's answers
     * unread) re-fires; otherwise the turn was answered by an earlier chunk (the turn-end event is recorded late) and
     * the probe looks above the reloaded tail. IDLE with the attempts unchanged; RUNNING while delegation work is
     * unfinished (its queued children are promoted) or work is owed.
     */
    public static function afterNoWork(bool $workOwedAfterReload, bool $hasUnfinishedDelegationWork, int $reloadedNewestIndex): static
    {
        $runRelease = new static();
        $runRelease->runState = AIConversationRunState::IDLE;
        if (!$workOwedAfterReload) {
            $runRelease->turnEndOutcome = AIConversationTurnEvent::OUTCOME_ANSWERED;
            $runRelease->turnEndRecordedLate = true;
            $runRelease->probingForWaitingSignal(self::WAITING_SIGNAL_PROBE_BEFORE_PUBLISH, $reloadedNewestIndex, 'noWork');
        }
        $runRelease->realtimeStatus = $hasUnfinishedDelegationWork || $workOwedAfterReload
            ? AIConversationRealtimeSignalService::STATUS_RUNNING
            : AIConversationRealtimeSignalService::STATUS_IDLE;
        $runRelease->reFiresAfterRelease = $workOwedAfterReload;
        $runRelease->promotesQueuedDelegations = $hasUnfinishedDelegationWork;
        return $runRelease;
    }

    /**
     * The turn started delegation work that is still unfinished: IDLE with attempts 0 (the conversation stays
     * conversational), RUNNING until the children's answers are read. Children's answers already unread at release
     * time re-fire; else the probe looks above the pre-turn index. Queued children are promoted.
     */
    public static function afterDelegationsStarted(bool $childrenAnswersUnread, int $highestIndexBeforeTurn): self
    {
        $runRelease = self::countingAttempts(0);
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
        if ($childrenAnswersUnread) {
            $runRelease->reFiresAfterRelease = true;
        } else {
            $runRelease->probingForWaitingSignal(self::WAITING_SIGNAL_PROBE_AFTER_PUBLISH, $highestIndexBeforeTurn, null);
        }
        $runRelease->promotesQueuedDelegations = true;
        return $runRelease;
    }

    /**
     * A turn that sub-agent notifications started ended with `wait_for_sub_agents`: the turn ends
     * WAITING_FOR_SUB_AGENTS, IDLE with attempts 0, RUNNING while the sub-agents work; a run end that landed after the
     * turn's last model call (its notifier saw the live claim) re-fires through the probe above the pre-turn index; owner
     * input that arrived during the turn (found by the caller's reload) re-fires at once; queued children are
     * promoted. No settled-turn follow-ups — no answer was given (no memory curation, no supervisor
     * beat, no purpose close).
     */
    public static function afterWaitingForSubAgents(int $highestIndexBeforeTurn, bool $ownerInputArrivedDuringTheTurn = false): self
    {
        $runRelease = self::countingAttempts(0);
        $runRelease->turnEndOutcome = AIConversationTurnEvent::OUTCOME_WAITING_FOR_SUB_AGENTS;
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
        if ($ownerInputArrivedDuringTheTurn) {
            $runRelease->reFiresAfterRelease = true;
            $runRelease->promotesQueuedDelegations = true;
            return $runRelease;
        }
        $runRelease->probingForWaitingSignal(self::WAITING_SIGNAL_PROBE_AFTER_PUBLISH, $highestIndexBeforeTurn, 'waitingForSubAgents');
        $runRelease->promotesQueuedDelegations = true;
        return $runRelease;
    }

    /** A delegation child yielded at a chunk boundary (its reloaded tail owes a turn): IDLE 0, RUNNING, re-fired. */
    public static function afterChildYield(): self
    {
        $runRelease = self::countingAttempts(0);
        $runRelease->runState = AIConversationRunState::IDLE;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
        $runRelease->reFiresAfterRelease = true;
        return $runRelease;
    }

    /**
     * A delegation child produced its verdict (or the mechanical child its terminal): the child's terminal owns the
     * release; IDLE is published unless the child yielded to an owner input accepted after its last look.
     */
    public static function afterDelegationChildVerdict(): static
    {
        $runRelease = new static();
        $runRelease->finishesIntoDelegationBatch = true;
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        return $runRelease;
    }

    /**
     * A root turn without real progress while inbound is owed (only loop steering appended): one more attempt. Under
     * the cap IDLE with the count carried, RUNNING, re-fired; over it the stall give-up reply is appended (it answers
     * the tail, so nothing re-fires for it), the turn ends GAVE_UP, IDLE with attempts 0, and the probe looks above
     * the pre-turn index.
     */
    public static function afterTurnWithoutRealProgress(int $resumeAttemptsBeforeTurn, int $highestIndexBeforeTurn): self
    {
        $runRelease = self::countingOneMoreAttempt($resumeAttemptsBeforeTurn);
        $runRelease->runState = AIConversationRunState::IDLE;
        if (!$runRelease->overCap) {
            $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
            $runRelease->reFiresAfterRelease = true;
            return $runRelease;
        }
        $runRelease->resumeAttempts = 0;
        $runRelease->appendsStallGiveUpReply = true;
        $runRelease->turnEndOutcome = AIConversationTurnEvent::OUTCOME_GAVE_UP;
        $runRelease->probingForWaitingSignal(self::WAITING_SIGNAL_PROBE_BEFORE_PUBLISH, $highestIndexBeforeTurn, null);
        $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        return $runRelease;
    }

    /**
     * A root turn answered: the turn ends ANSWERED, IDLE with attempts 0. Owner input that arrived mid-turn re-fires
     * (RUNNING); otherwise the probe looks above the pre-turn index, and when nothing re-fires the settled-turn
     * follow-ups run.
     */
    public static function afterAnswered(bool $ownerInputArrivedMidTurn, int $highestIndexBeforeTurn): self
    {
        $runRelease = self::countingAttempts(0);
        $runRelease->turnEndOutcome = AIConversationTurnEvent::OUTCOME_ANSWERED;
        $runRelease->runState = AIConversationRunState::IDLE;
        if ($ownerInputArrivedMidTurn) {
            $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
            $runRelease->reFiresAfterRelease = true;
        } else {
            $runRelease->probingForWaitingSignal(self::WAITING_SIGNAL_PROBE_BEFORE_PUBLISH, $highestIndexBeforeTurn, 'answered');
            $runRelease->realtimeStatus = AIConversationRealtimeSignalService::STATUS_IDLE;
        }
        $runRelease->runsSettledTurnFollowUps = true;
        return $runRelease;
    }

    /** The realtime status to publish once the release is written — after a child terminal, by whether it yielded. */
    public function getRealtimeStatus(bool $childYieldedForAcceptedInput): ?string
    {
        return $this->finishesIntoDelegationBatch && $childYieldedForAcceptedInput
            ? $this->realtimeStatusWhenChildYielded
            : $this->realtimeStatus;
    }

    protected static function afterDeterministicFailure(bool $isDelegationChildInBatch): self
    {
        $runRelease = self::countingAttempts(0);
        if ($isDelegationChildInBatch) {
            return $runRelease->finishingIntoDelegationBatch(null);
        }
        return $runRelease->endingFailed(withScheduleRecord: true);
    }

    protected static function countingOneMoreAttempt(int $resumeAttemptsBeforeTurn): self
    {
        return self::countingAttempts($resumeAttemptsBeforeTurn + 1);
    }

    protected static function countingAttempts(int $resumeAttempts): static
    {
        $runRelease = new static();
        $runRelease->resumeAttempts = $resumeAttempts;
        $runRelease->overCap = $resumeAttempts > self::MAX_RESUME_ATTEMPTS;
        return $runRelease;
    }

    protected function probingForWaitingSignal(string $waitingSignalProbe, int $aboveIndex, ?string $traceBranch): void
    {
        $this->waitingSignalProbe = $waitingSignalProbe;
        $this->waitingSignalProbeAboveIndex = $aboveIndex;
        $this->waitingSignalProbeTraceBranch = $traceBranch;
    }

    /**
     * Under the cap after a failed turn: IDLE with the handover marker the claim cleared (the owed-turn recovery pass
     * selects `IDLE AND agentTurnOwedAt IS NOT NULL`) and the RUNNING status — the turn is still owed.
     */
    protected function owingTheTurnAgain(DateTime $now): self
    {
        $this->runState = AIConversationRunState::IDLE;
        $this->agentTurnOwedAt = $now;
        $this->realtimeStatus = AIConversationRealtimeSignalService::STATUS_RUNNING;
        return $this;
    }

    /** FAILED with the turn-end event and the FAILED status; the schedule records a failed firing when asked to. */
    protected function endingFailed(bool $withScheduleRecord): self
    {
        $this->runState = AIConversationRunState::FAILED;
        $this->turnEndOutcome = AIConversationTurnEvent::OUTCOME_FAILED;
        $this->scheduleRunCompleted = $withScheduleRecord ? false : null;
        $this->realtimeStatus = AIConversationRealtimeSignalService::STATUS_FAILED;
        return $this;
    }

    /** The child's FAILED terminal (written by the caller) owns the release; FAILED is published unless the child yielded. */
    protected function finishingIntoDelegationBatch(?string $realtimeStatusWhenChildYielded): self
    {
        $this->finishesIntoDelegationBatch = true;
        $this->realtimeStatus = AIConversationRealtimeSignalService::STATUS_FAILED;
        $this->realtimeStatusWhenChildYielded = $realtimeStatusWhenChildYielded;
        return $this;
    }
}
