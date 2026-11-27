import { BACKLOG_LINE_PACE_MS, LINE_PACE_MS } from "./activity-ticker";
import { SUCCESS_BEAT_MS } from "./use-activity-ticker";
import { ACTIVITY_REQUEST_WINDOW_MS, RUNNING_FLOOR_MS } from "./use-conversation-activity";
/** The package's timing: what a chat uses when neither the chat nor the environment names a value. */
export const DEFAULT_AGENT_CHAT_ACTIVITY_TIMING = {
    activityRequestWindowMs: ACTIVITY_REQUEST_WINDOW_MS,
    runningFloorMs: RUNNING_FLOOR_MS,
    linePaceMs: LINE_PACE_MS,
    backlogLinePaceMs: BACKLOG_LINE_PACE_MS,
    successBeatMs: SUCCESS_BEAT_MS,
};
const isUsableMilliseconds = (value) => typeof value === "number" && Number.isFinite(value) && value >= 0;
/**
 * The timing of one chat: each field from the first source that sets a usable value — the chat's own
 * (`AgentChat activityTiming`), then the environment's (`AgentsEnvironment.chatActivityTiming`), then the package's.
 */
export const resolveAgentChatActivityTiming = (...sources) => {
    const resolved = { ...DEFAULT_AGENT_CHAT_ACTIVITY_TIMING };
    Object.keys(resolved).forEach((key) => {
        const source = sources.find((candidate) => isUsableMilliseconds(candidate?.[key]));
        if (source)
            resolved[key] = source[key];
    });
    return resolved;
};
