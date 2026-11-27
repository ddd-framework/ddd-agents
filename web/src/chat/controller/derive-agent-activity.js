import { textKey } from "../../provider/text-key";
import { SIGNAL_SOURCES_THAT_OWE_NO_WORK } from "../last-wire-message-is-unanswered-user-message";
/** Mirrors App\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool::NAME. */
const DELEGATE_TOOL_NAME = "delegate";
/** Terminal tool-call statuses — mirrors ToolCall::isResolved (COMPLETED / FAILED / CANCELLED). */
const RESOLVED_STATUSES = ["COMPLETED", "FAILED", "CANCELLED"];
const isResolved = (status) => status != null && RESOLVED_STATUSES.includes(status);
/** Terminal child run-states — mirrors AIConversationRunState::TERMINAL (COMPLETED / FAILED / CANCELLED). */
const TERMINAL_RUN_STATES = ["COMPLETED", "FAILED", "CANCELLED"];
/** A sub-agent run still waiting to start or actively working (mirrors DelegationSubAgentRun::STATUS_*). */
const UNRESOLVED_RUN_STATUSES = ["QUEUED", "RUNNING"];
/**
 * A tool call is EFFECTIVELY resolved when its stored status says so — or, for a `delegate` call, when every
 * expanded child already reached a terminal run state: the children are the truth of a fan-out, the stored
 * status is a mirror that a write race can leave stale (a delegate call left RUNNING kept the whole customer
 * chat "thinking" for the rest of a setup). An empty or missing expansion proves nothing and falls back to the status.
 */
/**
 * Rows that never start or continue a turn: the bookkeeping rows (ERROR, CHECKPOINT) and the SIGNAL rows that owe no
 * work (the frontend's widget render report, the platform's own widget notice, phone call markers, a suppressed
 * repeat). They are stored with role USER and may land after the agent's answer; read as the tail they would keep the
 * "Working on it…" line under a finished answer forever.
 */
const isRowThatOwesNoWork = (message) => message.messageType === "ERROR" ||
    message.messageType === "CHECKPOINT" ||
    (message.messageType === "SIGNAL" && SIGNAL_SOURCES_THAT_OWE_NO_WORK.includes(message.signalSource ?? ""));
const isEffectivelyResolved = (message) => {
    if (message == null)
        return false;
    if (isResolved(message.toolCall?.status))
        return true;
    if (message.toolCall?.name !== DELEGATE_TOOL_NAME)
        return false;
    const children = message.delegatedConversations ?? [];
    if (children.length === 0)
        return false;
    // A partial expansion (page cap) proves nothing: the call declared N tasks, N children must be visible.
    const declaredTasks = message.toolCall?.arguments?.tasks;
    if (Array.isArray(declaredTasks) && declaredTasks.length > children.length)
        return false;
    return children.every((child) => TERMINAL_RUN_STATES.includes(child.runState ?? ""));
};
/** How many sub-agents spawned by this message are still working. */
export const countOutstandingChildren = (message) => {
    const children = message.delegatedConversations ?? [];
    if (children.length > 0) {
        return children.filter((child) => !TERMINAL_RUN_STATES.includes(child.runState ?? "")).length;
    }
    if (message.toolCall?.name !== DELEGATE_TOOL_NAME) {
        return 0;
    }
    // A NEW row (one `delegate` call = one sub-agent) carries its own run record instead of
    // `delegatedConversations`; no child expanded, so the record's CURRENT run is the truth of whether it is done.
    const subAgent = message.subAgent;
    if (subAgent != null) {
        const currentRun = (subAgent.runs ?? []).find((run) => run.runNumber === subAgent.currentRunNumber);
        return currentRun != null && UNRESOLVED_RUN_STATUSES.includes(currentRun.status ?? "") ? 1 : 0;
    }
    if (isResolved(message.toolCall?.status)) {
        return 0;
    }
    const declaredTasks = message.toolCall?.arguments?.tasks;
    return Array.isArray(declaredTasks) && declaredTasks.length > 0 ? declaredTasks.length : 1;
};
/** A tool call whose result is still to come — its progress lives in `toolCall.status`, never in the row's status. */
export const hasUnresolvedToolCall = (message) => message.toolCall != null && !isEffectivelyResolved(message);
/** Whether this message's delegation can still change: a child is still working, or the call is not resolved yet. */
export const hasDelegationInFlight = (message) => countOutstandingChildren(message) > 0 ||
    (message.toolCall?.name === DELEGATE_TOOL_NAME && !isEffectivelyResolved(message));
/**
 * Derive "what the agent is doing right now" for the agent activity indicator PURELY from the
 * live conversation data: the message tree (refreshed on each realtime-signal tick / on open) plus the signal's
 * coarse runStatus ("running" | "idle" | "failed" | null). No network. Mirrors the backend truth —
 * AIConversation.runState (IDLE/RUNNING/AWAITING_DELEGATES/…) and per-message status/messageType/toolCall — but reads
 * it off the messages the FE already holds, so the indicator is correct even before the first signal emission.
 */
export const deriveAgentActivity = (messages, runStatus) => {
    if (runStatus === "failed") {
        // Failure surfaces via ConversationView's error state, not the activity line.
        return { active: false, label: "" };
    }
    // An optimistic (just-sent, not yet server-assigned) row carries no index — it belongs at the TAIL, the newest
    // thing in the conversation, never at the front, where `?? 0` would park it ahead of every real message.
    const sorted = [...(messages ?? [])].filter((message) => !isRowThatOwesNoWork(message)).sort((a, b) => (a.indexInConversation ?? Number.POSITIVE_INFINITY) - (b.indexInConversation ?? Number.POSITIVE_INFINITY));
    const lastMessage = sorted[sorted.length - 1];
    // The owner just sent — the optimistic bubble, or a saved row still awaiting the first response — starts the face
    // thinking immediately, before the server's "running" ping arrives (which can lag the send by about a second);
    // checked before the `idle` short-circuit below, whose `runStatus` can still report the PREVIOUS turn's rest state.
    // A rejected POST resets `aiThinking` itself (conversations-message-post.case.ts), so no separate reset is needed.
    if (lastMessage?.role === "USER") {
        return { active: true, label: "" };
    }
    // Sub-agents still working → the orchestrator is parked on its children (backend runState AWAITING_DELEGATES).
    // Counted per CHILD, not per delegate message: a fan-out (`delegate` with N tasks, or a tool result carrying
    // `delegationTasks`, e.g. a LARGE website edit's page packets) is ONE message with N children. The expanded
    // `delegatedConversations` carry each child's runState; without the expand, a `delegate` call still awaiting its
    // verdict counts its declared tasks, else one.
    const unresolvedDelegateCount = sorted.reduce((count, message) => count + countOutstandingChildren(message), 0);
    // The turn is at rest and nothing is outstanding → idle, no indicator.
    if (runStatus === "idle" && unresolvedDelegateCount === 0) {
        return { active: false, label: "" };
    }
    if (unresolvedDelegateCount > 0) {
        return {
            active: true,
            label: textKey("Waiting for %count% delegations..."),
            replacements: { count: unresolvedDelegateCount },
            context: unresolvedDelegateCount === 1 ? "one" : "other",
        };
    }
    // A tool-call that hasn't resolved yet → that tool is running now (a delegate call whose children all finished
    // is resolved in effect, whatever its stored status says).
    if (lastMessage?.messageType === "TOOL_CALL" && !isEffectivelyResolved(lastMessage)) {
        return {
            active: true,
            label: textKey("Running: %tool%..."),
            replacements: { tool: lastMessage.toolCall?.name ?? "Tool" },
            toolRunning: true,
        };
    }
    // The model is producing a turn (signal running) — a trailing owner row was already caught above.
    if (runStatus === "running") {
        return { active: true, label: "" };
    }
    return { active: false, label: "" };
};
