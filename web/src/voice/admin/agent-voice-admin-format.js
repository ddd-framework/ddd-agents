// Labels, states and small helpers of the call screens. Pure: no React.
import { normalizeDddError } from "@mgamadeus/ddd-web/contract";
import { AudioLines, Ban, BellOff, BellRing, Bot, CircleCheck, CircleStop, CircleX, Gauge, TimerOff, Undo2, UserRound, } from "lucide-react";
import { formatUiNumber, m } from "@mgamadeus/ddd-admin-ui/i18n";
import { defineStatusMap } from "@mgamadeus/ddd-admin-ui/logic";
/** Where a call stands, as the server read it (`callState` of the answers). */
export const AGENT_VOICE_CALL_STATE = defineStatusMap({
    RINGING: {
        label: m("Ringing", { context: "agent-voice-call-state" }),
        tone: "warning",
        icon: BellRing,
        description: m("Nobody has answered yet", { context: "agent-voice-call-state" }),
    },
    IN_PROGRESS: {
        label: m("Running", { context: "agent-voice-call-state" }),
        tone: "success",
        icon: AudioLines,
        description: m("The call was answered and is going on", { context: "agent-voice-call-state" }),
    },
    ENDED: {
        label: m("Ended", { context: "agent-voice-call-state" }),
        tone: "neutral",
        icon: CircleStop,
        description: m("The call is over", { context: "agent-voice-call-state" }),
    },
}, { context: "agent-voice-call-state" });
/** `AgentVoiceCall.endReason`: the reasons the call writes itself. A diagnostic value shows as it came. */
export const AGENT_VOICE_CALL_END_REASON = defineStatusMap({
    COMPLETED: {
        label: m("Completed", { context: "agent-voice-call-end-reason" }),
        tone: "success",
        icon: CircleCheck,
        description: m("Both sides hung up normally", { context: "agent-voice-call-end-reason" }),
    },
    ENDED_BY_AGENT: {
        label: m("Ended by the agent", { context: "agent-voice-call-end-reason" }),
        tone: "info",
        icon: Bot,
        description: m("The agent hung up the running call or withdrew its ring", { context: "agent-voice-call-end-reason" }),
    },
    LIMIT_REACHED: {
        label: m("Limit reached", { context: "agent-voice-call-end-reason" }),
        tone: "warning",
        icon: Gauge,
        description: m("The call ran into its time, daily or budget limit", { context: "agent-voice-call-end-reason" }),
    },
    FAILED: {
        label: m("Failed", { context: "agent-voice-call-end-reason" }),
        tone: "danger",
        icon: CircleX,
        description: m("The session ended on an error of the provider, the model or the worker", {
            context: "agent-voice-call-end-reason",
        }),
    },
    NOT_ANSWERED: {
        label: m("Not answered", { context: "agent-voice-call-end-reason" }),
        tone: "neutral",
        icon: BellOff,
        description: m("Nobody picked up", { context: "agent-voice-call-end-reason" }),
    },
    DECLINED: {
        label: m("Declined", { context: "agent-voice-call-end-reason" }),
        tone: "neutral",
        icon: Ban,
        description: m("Every client the call rang on declined it", { context: "agent-voice-call-end-reason" }),
    },
    EXPIRED: {
        label: m("Expired", { context: "agent-voice-call-end-reason" }),
        tone: "neutral",
        icon: TimerOff,
        description: m("Nobody answered before the ringing stopped", { context: "agent-voice-call-end-reason" }),
    },
    CANCELED: {
        label: m("Canceled", { context: "agent-voice-call-end-reason" }),
        tone: "neutral",
        icon: Undo2,
        description: m("The side that started the call withdrew it before anyone answered", {
            context: "agent-voice-call-end-reason",
        }),
    },
}, { context: "agent-voice-call-end-reason" });
/** The end reasons a list can be filtered by, in the order the filter offers them. */
export const AGENT_VOICE_CALL_END_REASONS = [
    "COMPLETED",
    "ENDED_BY_AGENT",
    "LIMIT_REACHED",
    "FAILED",
    "NOT_ANSWERED",
    "DECLINED",
    "EXPIRED",
    "CANCELED",
];
/** `AgentVoiceCall.direction`: who started the call. */
export const AGENT_VOICE_CALL_DIRECTION = defineStatusMap({
    INBOUND: {
        label: m("The owner called", { context: "agent-voice-call-direction" }),
        tone: "neutral",
        icon: UserRound,
        description: m("The owner started a voice chat with the agent", { context: "agent-voice-call-direction" }),
    },
    OUTBOUND: {
        label: m("The agent rang", { context: "agent-voice-call-direction" }),
        tone: "neutral",
        icon: Bot,
        description: m("The agent rang the owner’s open clients", { context: "agent-voice-call-direction" }),
    },
}, { context: "agent-voice-call-direction" });
/**
 * A backend date-time (`Y-m-d H:i:s`) as milliseconds on one clock, for the difference of two of them only: both are
 * read in the same zone, so the zone the backend writes them in does not matter. Null when it is none.
 */
const momentOf = (dateTime) => {
    if (!dateTime)
        return null;
    const moment = Date.parse(`${dateTime.trim().replace(" ", "T")}Z`);
    return Number.isNaN(moment) ? null : moment;
};
/**
 * When a call began: the moment it was created (the owner opened the voice chat, or the ring started) where the
 * answer carries it, else the moment it was answered; null for a call nobody answered whose creation is not sent.
 */
export const agentVoiceAdminCallStartTimeOf = (call) => call.changeHistory?.createdTime ?? call.startedAt ?? null;
/** How long a call lasted, from the moment it was answered to its end; null while it runs or when nobody answered. */
export const agentVoiceAdminCallDurationInSecondsOf = (call) => {
    const startedAt = momentOf(call.startedAt);
    const endedAt = momentOf(call.endedAt);
    if (startedAt === null || endedAt === null)
        return null;
    return Math.max(0, Math.round((endedAt - startedAt) / 1000));
};
/**
 * What the server derived for the list's calls (the state, the costs), by call id: reads the set the list answer
 * carries (`AgentVoiceAdminCallsResponse.agentVoiceCallDerivedValues`). A call the answer names no entry for has none
 * here: its row then shows no state and no costs.
 */
export const agentVoiceAdminCallDerivedValuesByCallIdOf = (agentVoiceCallDerivedValues) => {
    const derivedValuesByCallId = new Map();
    for (const derivedValuesOfCall of agentVoiceCallDerivedValues?.elements ?? [])
        derivedValuesByCallId.set(derivedValuesOfCall.callId, derivedValuesOfCall);
    return derivedValuesByCallId;
};
/** The fraction digits a call's costs are rounded to: a call costs cents, two digits would show most calls alike. */
const CALL_COSTS_FRACTION_DIGITS = { minimumFractionDigits: 2, maximumFractionDigits: 4 };
/** A call's costs, rounded, in the UI language. Call it at render time, so a language switch formats anew. */
export const formatAgentVoiceAdminCallCosts = (bookedCosts) => {
    try {
        return formatUiNumber(bookedCosts.amount, {
            style: "currency",
            currency: bookedCosts.currency.isoCode,
            ...CALL_COSTS_FRACTION_DIGITS,
        });
    }
    catch {
        // A currency code the browser's number format does not know: the rounded number beside the code as it came.
        return `${formatUiNumber(bookedCosts.amount, CALL_COSTS_FRACTION_DIGITS)} ${bookedCosts.currency.isoCode}`;
    }
};
const DAY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
/** A moment as the routes' filters read it: `Y-m-d H:i:s` in UTC. */
const toUtcDateTime = (moment) => moment.toISOString().slice(0, 19).replace("T", " ");
/**
 * The viewer's calendar day `YYYY-MM-DD` as a moment in UTC: its first second, or with `isEndOfDay` its last.
 * Undefined for an empty value or a day the calendar lacks.
 */
export const toAgentVoiceAdminDayBound = (day, isEndOfDay) => {
    const dayParts = DAY_PATTERN.exec(day.trim());
    if (!dayParts)
        return undefined;
    const [year, month, dayOfMonth] = [Number(dayParts[1]), Number(dayParts[2]), Number(dayParts[3])];
    const moment = isEndOfDay ? new Date(year, month - 1, dayOfMonth, 23, 59, 59) : new Date(year, month - 1, dayOfMonth, 0, 0, 0);
    if (moment.getFullYear() !== year || moment.getMonth() !== month - 1 || moment.getDate() !== dayOfMonth)
        return undefined;
    return toUtcDateTime(moment);
};
/** A whole, non-negative number typed into a filter (an account id, seconds); undefined for anything else. */
export const readAgentVoiceAdminWholeNumber = (text) => {
    const trimmedText = text.trim();
    return /^\d{1,15}$/.test(trimmedText) ? Number(trimmedText) : undefined;
};
/** The error codes of the call routes a screen shows as a state of its own. */
export const AGENT_VOICE_ADMIN_ERROR_FEATURE_NOT_AVAILABLE = "feature_not_available";
export const AGENT_VOICE_ADMIN_ERROR_CALL_NOT_FOUND = "call_not_found";
/**
 * Whether a failed request was refused with `errorCode`. The routes answer the code as the error message; a base
 * query that translates error bodies keeps the original beside it (`errorEnglish`).
 */
export const isAgentVoiceAdminError = (error, errorCode) => {
    if (error === undefined || error === null)
        return false;
    const normalizedError = normalizeDddError(error);
    const errorBody = typeof normalizedError.body === "object" && normalizedError.body !== null
        ? normalizedError.body
        : {};
    return [errorBody.errorEnglish, errorBody.error, normalizedError.message].includes(errorCode);
};
