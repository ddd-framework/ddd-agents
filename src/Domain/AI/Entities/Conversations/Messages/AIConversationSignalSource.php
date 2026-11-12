<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSourceDefaultAnswers;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSpeaker;

/**
 * The AI core's signal sources — the vocabulary of {@see AIConversationMessage::$signalSource}
 * the core itself writes. It is one {@see ConversationSignalSource} enum among the registered ones
 * ({@see ConversationSignalSources}, registered first): it answers the BASIC questions per case, the derived questions
 * come from {@see ConversationSignalSourceDefaultAnswers}, and its CORE VOCABULARY questions name its own cases. Each
 * question keeps today's members exactly, including the lists that drifted apart (their differences are named on the
 * method and consolidated there, not here). The property stays a string ({@see AIConversationMessage::$signalSource}
 * also carries markers on TEXT rows, a tool name on TOOL_MESSAGE rows and an error code on ERROR rows): read
 * it through {@see ConversationSignalSources::tryFrom()}. The domain sources live in their domain's enum (the
 * application's enums and the phone channel's
 * {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource}).
 * {@see AIConversationMessage}'s `SIGNAL_SOURCE_*` constants carry the meaning of the core sources; a domain source has
 * no constant there — its writer passes the typed case of its domain's enum (`<Domain>ConversationSignalSource::<CASE>->value`).
 */
enum AIConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    case LOOP_NUDGE = 'LOOP_NUDGE';
    case INJECTION_REMOVED = 'INJECTION_REMOVED';
    case MID_TURN_OWNER_INBOUND = 'MID_TURN_OWNER_INBOUND';
    case KICKOFF_OWNER_INBOUND = 'KICKOFF_OWNER_INBOUND';
    case MID_TURN_SIGNAL_REPLAY = 'MID_TURN_SIGNAL_REPLAY';
    case MEMORY_CONFLICT = 'MEMORY_CONFLICT';
    case MEMORY_CLARIFY = 'MEMORY_CLARIFY';
    case FRONTEND_WIDGET_CLICK = 'FRONTEND_WIDGET_CLICK';
    case FRONTEND_CHANNEL_CONNECTION = 'FRONTEND_CHANNEL_CONNECTION';
    case FRONTEND_NAVIGATION = 'FRONTEND_NAVIGATION';
    case OWNER_ACTION_CONFIRM = 'OWNER_ACTION_CONFIRM';
    /** A MARKER on an assistant TEXT row (the unattended run's report), not a signal. */
    case OWNER_REPORT = 'OWNER_REPORT';
    case SUPERVISOR_STEERING = 'SUPERVISOR_STEERING';
    case SUPERVISOR_CORRECTION = 'SUPERVISOR_CORRECTION';
    case PURPOSE_CONSEQUENCE = 'PURPOSE_CONSEQUENCE';
    case FRONTEND_WIDGET_INVALID = 'FRONTEND_WIDGET_INVALID';
    /**
     * The frontend's render report of a message's widget blocks: which blocks did not show what
     * they should (`empty`, `failed` with a code). Context for the model's NEXT turn — it owes no work, so it never
     * drives a turn of its own; sent at most once per message, never for a replayed one.
     */
    case FRONTEND_WIDGET_RENDER_REPORT = 'FRONTEND_WIDGET_RENDER_REPORT';
    case DASHBOARD_WIDGET_CONTEXT = 'DASHBOARD_WIDGET_CONTEXT';
    case PURPOSE_KICKOFF = 'PURPOSE_KICKOFF';
    case DELEGATIONS_SETTLED = 'DELEGATIONS_SETTLED';
    case BACKGROUND_WORK_FINISHED = 'BACKGROUND_WORK_FINISHED';
    case TURN_CONTINUATION = 'TURN_CONTINUATION';
    case TASK_PLAN_OPEN_BOUNCE = 'TASK_PLAN_OPEN_BOUNCE';
    case TASK_PLAN_OPEN_BOUNCE_DECISION = 'TASK_PLAN_OPEN_BOUNCE_DECISION';
    case TURN_OBLIGATION_BOUNCE = 'TURN_OBLIGATION_BOUNCE';
    case PLAN_BOOKKEEPING = 'PLAN_BOOKKEEPING';
    case OWNER_QUESTION_PICK = 'OWNER_QUESTION_PICK';
    /** The platform applied the owner's edit of a selection widget (renamed from OWNER_CARD_EDIT_APPLIED). */
    case OWNER_WIDGET_EDIT_APPLIED = 'OWNER_WIDGET_EDIT_APPLIED';
    /** The stored value of {@see OWNER_WIDGET_EDIT_APPLIED} before the rename — kept so stored rows still parse; never written. */
    case LEGACY_OWNER_CARD_EDIT_APPLIED = 'OWNER_CARD_EDIT_APPLIED';
    /** The platform wrote a widget into the chat on its own; which widget and why ride in its `emittedBy`. */
    case PLATFORM_WIDGET_EMITTED = 'PLATFORM_WIDGET_EMITTED';
    case CONTINUE_AFTER_PAUSE = 'CONTINUE_AFTER_PAUSE';
    case BUDGET_CLOSING = 'BUDGET_CLOSING';
    /** The note before the forced `send_owner_report` step of an EMAIL run: the text just written is the report the call binds. */
    case OWNER_REPORT_FORCED_CALL = 'OWNER_REPORT_FORCED_CALL';
    /** A MARKER on a TEXT row: the closing text that hands the turn to the owner. */
    case OWNER_REPORT_WITH_QUESTION = 'OWNER_REPORT_WITH_QUESTION';
    case REPEATED_TEXT_SUPPRESSED = 'REPEATED_TEXT_SUPPRESSED';
    /**
     * The loop held a final text whose `ComposedView` reading dropped parts of the tree: the
     * finding lines, asking for the corrected block in the next response. Once per owner turn.
     */
    case WIDGET_TREE_FINDINGS = 'WIDGET_TREE_FINDINGS';
    /** A sub-agent's run ended; the parent is resumed to read the notification. No writer before 103b-4. */
    case SUB_AGENT_RUN_ENDED = 'SUB_AGENT_RUN_ENDED';
    /** A sub-agent's run ended; the notification waits for the parent's next genuine turn. No writer before 103b-4. */
    case SUB_AGENT_RUN_ENDED_WITHOUT_RESUME = 'SUB_AGENT_RUN_ENDED_WITHOUT_RESUME';
    /** The orchestrator writes to its running sub-agent. No writer before 103b-6. */
    case ORCHESTRATOR_MESSAGE = 'ORCHESTRATOR_MESSAGE';
    /**
     * The loop's replay of a {@see SUB_AGENT_RUN_ENDED} row that landed while a model call ran: the
     * properties of {@see MID_TURN_SIGNAL_REPLAY}, and the opener check counts it as the run end it replays.
     */
    case SUB_AGENT_RUN_ENDED_REPLAY = 'SUB_AGENT_RUN_ENDED_REPLAY';
    /** The replay of a {@see SUB_AGENT_RUN_ENDED_WITHOUT_RESUME} row: neutral in the opener check, like its original. */
    case SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY = 'SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY';

    // --- the replay of a stored source ---

    /**
     * The source of the loop's replay row for a row of the stored source `$storedSignalSource`:
     * a sub-agent's run end keeps its meaning in its own replay source, every other source (a result an application
     * process delivered, the orchestrator's message, an owner's row, none) is replayed as
     * {@see MID_TURN_SIGNAL_REPLAY}. Over the stored string: the release path replays sources of the domains' enums
     * too. Pure.
     */
    public static function replaySourceFor(?string $storedSignalSource): string
    {
        return match ($storedSignalSource) {
            self::SUB_AGENT_RUN_ENDED->value => self::SUB_AGENT_RUN_ENDED_REPLAY->value,
            self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME->value => self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY->value,
            default => self::MID_TURN_SIGNAL_REPLAY->value,
        };
    }

    // --- basic questions ---

    public function getSpeaker(): ConversationSignalSpeaker
    {
        return match ($this) {
            self::FRONTEND_WIDGET_CLICK, self::FRONTEND_CHANNEL_CONNECTION, self::FRONTEND_NAVIGATION, self::OWNER_ACTION_CONFIRM => ConversationSignalSpeaker::OWNER,
            self::OWNER_REPORT, self::OWNER_REPORT_WITH_QUESTION, self::DELEGATIONS_SETTLED, self::BACKGROUND_WORK_FINISHED,
            self::TURN_CONTINUATION, self::SUB_AGENT_RUN_ENDED, self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
            self::ORCHESTRATOR_MESSAGE => ConversationSignalSpeaker::AGENT,
            self::SUPERVISOR_STEERING, self::SUPERVISOR_CORRECTION => ConversationSignalSpeaker::SUPERVISOR,
            self::LOOP_NUDGE, self::MID_TURN_OWNER_INBOUND, self::KICKOFF_OWNER_INBOUND, self::MID_TURN_SIGNAL_REPLAY,
            self::SUB_AGENT_RUN_ENDED_REPLAY, self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY,
            self::TASK_PLAN_OPEN_BOUNCE, self::TASK_PLAN_OPEN_BOUNCE_DECISION, self::TURN_OBLIGATION_BOUNCE, self::PLAN_BOOKKEEPING,
            self::OWNER_QUESTION_PICK, self::CONTINUE_AFTER_PAUSE, self::BUDGET_CLOSING, self::OWNER_REPORT_FORCED_CALL,
            self::REPEATED_TEXT_SUPPRESSED, self::WIDGET_TREE_FINDINGS => ConversationSignalSpeaker::LOOP,
            self::INJECTION_REMOVED, self::MEMORY_CONFLICT, self::MEMORY_CLARIFY,
            self::PURPOSE_CONSEQUENCE, self::FRONTEND_WIDGET_INVALID, self::FRONTEND_WIDGET_RENDER_REPORT, self::DASHBOARD_WIDGET_CONTEXT,
            self::PURPOSE_KICKOFF, self::OWNER_WIDGET_EDIT_APPLIED, self::LEGACY_OWNER_CARD_EDIT_APPLIED,
            self::PLATFORM_WIDGET_EMITTED => ConversationSignalSpeaker::PLATFORM,
        };
    }

    /** The purpose kickoff (a work order) or the supervisor's correction (the domains' turn starters live in their enums). */
    public function startsATurn(): bool
    {
        return in_array($this, [self::PURPOSE_KICKOFF, self::SUPERVISOR_CORRECTION], true);
    }

    /**
     * The owner's click, channel connection and confirm (the owner's own action during a
     * running turn reaches the model at its next step instead of being asked for again) — not a form widget's submit,
     * which starts a turn of its own (an application's work result is its own enum's). A sub-agent's run end and the
     * orchestrator's message reach a running turn the same way.
     */
    public function isPickedUpByRunningTurn(): bool
    {
        return in_array($this, [
            self::FRONTEND_WIDGET_CLICK,
            self::FRONTEND_CHANNEL_CONNECTION,
            self::OWNER_ACTION_CONFIRM,
            self::SUB_AGENT_RUN_ENDED,
            self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
            self::ORCHESTRATOR_MESSAGE,
        ], true);
    }

    /** Pure markers: the report markers on TEXT rows, the suppressed repeat, the platform's widget notice and the frontend's render report (the call chips are the phone enum's). */
    public function owesWork(): bool
    {
        return !in_array($this, [
            self::OWNER_REPORT,
            self::OWNER_REPORT_WITH_QUESTION,
            self::REPEATED_TEXT_SUPPRESSED,
            self::PLATFORM_WIDGET_EMITTED,
            self::FRONTEND_WIDGET_RENDER_REPORT,
        ], true);
    }

    /** The owner changed something (click, confirm, channel connection) or children finished their work. */
    public function invalidatesEarlierReads(): bool
    {
        return in_array($this, [
            self::FRONTEND_WIDGET_CLICK,
            self::OWNER_ACTION_CONFIRM,
            self::FRONTEND_CHANNEL_CONNECTION,
            self::DELEGATIONS_SETTLED,
            self::BACKGROUND_WORK_FINISHED,
            self::SUB_AGENT_RUN_ENDED,
            self::SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
        ], true);
    }

    /**
     * The owner's frontend actions, the widget failover, the render report and the dashboard widget's start context — never a source with
     * server semantics (a work order, a supervisor or loop signal, a phone or report event).
     */
    public function isPostedByTheClient(): bool
    {
        return in_array($this, [
            self::FRONTEND_WIDGET_CLICK,
            self::FRONTEND_CHANNEL_CONNECTION,
            self::FRONTEND_NAVIGATION,
            self::OWNER_ACTION_CONFIRM,
            self::FRONTEND_WIDGET_INVALID,
            self::FRONTEND_WIDGET_RENDER_REPORT,
            self::DASHBOARD_WIDGET_CONTEXT,
        ], true);
    }

    /** A click, an action confirm — a channel connection is no statement. */
    public function carriesAnOwnerStatement(): bool
    {
        return in_array($this, [self::FRONTEND_WIDGET_CLICK, self::OWNER_ACTION_CONFIRM], true);
    }

    /** A dashboard task started it, a navigation out of or back into the chat (a call that came in is the phone enum's). */
    public function marksOwnerPresence(): bool
    {
        return in_array($this, [self::DASHBOARD_WIDGET_CONTEXT, self::FRONTEND_NAVIGATION], true);
    }

    // --- presentation properties ---

    /**
     * A dashboard task or a purpose's kickoff opened the conversation (an application's work order and the phone call
     * are their enums').
     */
    public function getMetricsEntryPoint(): ?string
    {
        return match ($this) {
            self::DASHBOARD_WIDGET_CONTEXT => AIConversationMetrics::ENTRY_POINT_DASHBOARD_WIDGET,
            self::PURPOSE_KICKOFF => AIConversationMetrics::ENTRY_POINT_PURPOSE_KICKOFF,
            default => null,
        };
    }

    /** The navigation signal's text is an instruction to the agent; the analysis shows only that the owner left or came back. */
    public function getAnalysisTranscriptLabel(): ?string
    {
        return $this === self::FRONTEND_NAVIGATION ? 'OWNER LEFT FOR / RETURNED FROM A FLOW OUTSIDE THE CHAT' : null;
    }

    // --- core vocabulary ---

    /** The current value and the legacy one stored rows keep. */
    public function isOwnerWidgetEditApplied(): bool
    {
        return $this === self::OWNER_WIDGET_EDIT_APPLIED || $this === self::LEGACY_OWNER_CARD_EDIT_APPLIED;
    }

    public function quotesOwnerRows(): bool
    {
        return $this === self::MID_TURN_OWNER_INBOUND || $this === self::KICKOFF_OWNER_INBOUND;
    }

    /** Both triggers share one chunk budget. */
    public function isTaskPlanOpenBounce(): bool
    {
        return $this === self::TASK_PLAN_OPEN_BOUNCE || $this === self::TASK_PLAN_OPEN_BOUNCE_DECISION;
    }

    /** The empty-turn nudge, the plan-open bounces, the suppressed repeated text and the held tree findings. */
    public function isLoopSteeringWithoutProgress(): bool
    {
        return $this === self::LOOP_NUDGE || $this->isTaskPlanOpenBounce() || $this === self::REPEATED_TEXT_SUPPRESSED
            || $this === self::WIDGET_TREE_FINDINGS;
    }

    /** {@see isLoopSteeringWithoutProgress()} without the suppressed repeated text. */
    public function isLoopNudgeOrTaskPlanOpenBounce(): bool
    {
        return $this === self::LOOP_NUDGE || $this->isTaskPlanOpenBounce();
    }

    /**
     * The nudge, the bounces, the turn-obligation bounce, the held tree findings and the agent's work continuations.
     * Differs from {@see isLoopSteeringWithoutProgress()}: no suppressed repeated text (the trace counts that one separately).
     */
    public function isLoopCorrectiveOrWorkContinuation(): bool
    {
        return $this->isLoopNudgeOrTaskPlanOpenBounce() || $this === self::TURN_OBLIGATION_BOUNCE || $this === self::WIDGET_TREE_FINDINGS
            || $this->continuesAgentWork();
    }

    /**
     * A supervisor steer forced an action, the step budget closed the turn. Differs from {@see isSupervisorSignal()}:
     * the budget closing instead of the supervisor's correction.
     */
    public function isPlatformSteer(): bool
    {
        return $this === self::SUPERVISOR_STEERING || $this === self::BUDGET_CLOSING;
    }
}
