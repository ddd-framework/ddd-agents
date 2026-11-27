/**
 * Whole seconds from the moment the call connected to its end, or to `nowMs` while it has not ended; 0 before it
 * connected, and never below 0 (a clock read before the connect moment).
 */
export const agentVoiceCallElapsedSecondsAt = (call, nowMs) => {
    if (call.connectedAtMs === undefined)
        return 0;
    return Math.max(0, Math.floor(((call.endedAtMs ?? nowMs) - call.connectedAtMs) / 1000));
};
