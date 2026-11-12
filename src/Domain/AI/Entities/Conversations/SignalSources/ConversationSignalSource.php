<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

use BackedEnum;

/**
 * A stored signal source (property-based modelling) — a case of a
 * string-backed enum whose value is the stored {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$signalSource}.
 * The AI core declares its own sources ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource});
 * a domain declares its sources in its own enum, found by {@see ConversationSignalSources} through the folder convention.
 *
 * Three groups of questions:
 * - BASIC: what a source IS. Every enum answers these for its cases (the trait's default for each is the common case).
 * - DERIVED: computed from the basic ones by {@see ConversationSignalSourceDefaultAnswers}; no enum answers them itself
 *   (the contract test enforces it).
 * - CORE VOCABULARY: questions that single out named cases of the AI core enum (the loop's machinery, a legacy value, a
 *   form's legacy click). The property table proves no basic property separates them (two cases that agree on
 *   every basic property differ on each of them); every other enum answers them through the trait.
 *
 * Every enum implementing this interface uses {@see ConversationSignalSourceDefaultAnswers}.
 */
interface ConversationSignalSource extends BackedEnum
{
    // --- basic questions ---

    /** Who speaks through the source. */
    public function getSpeaker(): ConversationSignalSpeaker;

    /** The signal starts a turn of its own (a work order, the owner's form submit, the supervisor's correction). */
    public function startsATurn(): bool;

    /** A running turn takes the signal over at its next model call instead of resuming the conversation. */
    public function isPickedUpByRunningTurn(): bool;

    /** The signal demands an answer of the agent; false = a pure marker (a call chip, a report marker, a notice) the owed-turn walks see through. */
    public function owesWork(): bool;

    /** After the signal an earlier read may no longer hold although no tool of this conversation wrote (the read dedup treats it like a write). */
    public function invalidatesEarlierReads(): bool;

    /** The client may post the source (the inject-signal and start-with-message endpoints) — the security gate, always explicit. */
    public function isPostedByTheClient(): bool;

    /** A later call of `$toolName` in the same turn consumes the signal (the release path's mid-turn replay looks for it). */
    public function isConsumedByToolCall(string $toolName): bool;

    /**
     * The owner's own action counts as their statement (the owner-text readers, a purpose's owner statements, the eval
     * trace).
     */
    public function carriesAnOwnerStatement(): bool;

    /** The signal marks the owner's presence beyond their own action (the metrics' owner origin). */
    public function marksOwnerPresence(): bool;

    // --- presentation properties (stored and model-facing bytes; null = the reader's own fallback) ---

    /**
     * The scorecard's `entryPoint` of a conversation whose FIRST row is this signal
     * ({@see \DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics::resolveEntryPoint()}); null = `other`.
     * Stored bytes: the value lands in `AIConversationScorecard::$entryPoint`.
     */
    public function getMetricsEntryPoint(): ?string;

    /**
     * The line the conversation analysis writes for a SIGNAL row of this source INSTEAD of its text — an owner action
     * whose text carries agent-facing instructions is rendered by name only (in the transcript lines of the host's
     * conversation analysis);
     * null = the analysis's other rules. Model-facing bytes: the analysis model reads the line.
     */
    public function getAnalysisTranscriptLabel(): ?string;

    // --- derived questions (ConversationSignalSourceDefaultAnswers) ---

    /** The owner's own action: speaker OWNER and invalidates earlier reads. */
    public function isOwnerAuthored(): bool;

    /** The owner did something in the frontend other than type: speaker OWNER and posted by the client. */
    public function isOwnerFrontendAction(): bool;

    /** An owner signal that starts a turn like the owner's own text: starts a turn, speaker OWNER. */
    public function startsAnOwnerTurn(): bool;

    /** A machine work order of an autonomous purpose: starts a turn, speaker PLATFORM. */
    public function isWorkOrder(): bool;

    /** The supervisor's correction, which starts a turn of its own: starts a turn, speaker SUPERVISOR. */
    public function startsASupervisorTurn(): bool;

    /** A steer or a correction of the supervisor: speaker SUPERVISOR. */
    public function isSupervisorSignal(): bool;

    /** An owner-side marker that owes no work — today the phone call's lifecycle (started / ended): speaker OWNER, owes no work. */
    public function isPhoneCallLifecycle(): bool;

    /** A click or an action confirm opened the turn: carries an owner statement and starts no turn of its own. */
    public function isOwnerClickOrConfirm(): bool;

    /** A marker on an assistant TEXT row (the unattended run's report, the closing text with a question): speaker AGENT, owes no work. */
    public function marksAnOwnerReport(): bool;

    /** Continues the agent's own work (children settled, background work finished, a chunk continuation): speaker AGENT, owes work. */
    public function continuesAgentWork(): bool;

    // --- core vocabulary (AIConversationSignalSource answers; every other enum through the trait) ---

    /** The platform applied the owner's selection-widget edit (the current value and the legacy one). */
    public function isOwnerWidgetEditApplied(): bool;

    /** A note whose `(message N)` tokens quote owner rows the model was told to answer. */
    public function quotesOwnerRows(): bool;

    /** A plan-open bounce of the final text (either trigger). */
    public function isTaskPlanOpenBounce(): bool;

    /** Loop-generated steering that is no progress of the turn (the resume-attempt cap counts a turn that produced only these). */
    public function isLoopSteeringWithoutProgress(): bool;

    /** The loop's nudge or a plan-open bounce (the eval's supervisor-checkpoint detection). */
    public function isLoopNudgeOrTaskPlanOpenBounce(): bool;

    /** The loop's own correctives and the continuations of its work (the eval trace glosses them to one line). */
    public function isLoopCorrectiveOrWorkContinuation(): bool;

    /** A platform intervention the conversation analysis must see to attribute a problem. */
    public function isPlatformSteer(): bool;
}
