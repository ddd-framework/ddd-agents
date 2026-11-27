/** The realtime message type of the agent ringing the owner's open clients. Its key is the call id. */
export const AGENT_VOICE_RING_MESSAGE_TYPE = "agent.voice.ring";
const isRecord = (value) => typeof value === "object" && value !== null && !Array.isArray(value);
/**
 * The ring a realtime message carries; null for a message of another type or a payload that is not a ring (no call id,
 * no answer token, no deadline).
 */
export const readAgentVoiceRingOfRealtimeMessage = (message) => {
    if (message.messageType !== AGENT_VOICE_RING_MESSAGE_TYPE)
        return null;
    let payload;
    try {
        payload = JSON.parse(message.payloadJson);
    }
    catch {
        return null;
    }
    if (!isRecord(payload))
        return null;
    const { callId, conversationId, answerToken, ringExpiresAt, ringExpiresAtUnixMs, callerLabelKey, callerContext, agentSpaceId } = payload;
    if (typeof callId !== "number" || typeof answerToken !== "string" || typeof ringExpiresAtUnixMs !== "number")
        return null;
    return {
        callId,
        conversationId: typeof conversationId === "number" ? conversationId : null,
        answerToken,
        ringExpiresAt: typeof ringExpiresAt === "string" ? ringExpiresAt : "",
        ringExpiresAtUnixMs,
        callerLabelKey: typeof callerLabelKey === "string" ? callerLabelKey : "",
        callerContext: isRecord(callerContext) ? callerContext : {},
        agentSpaceId: typeof agentSpaceId === "number" ? agentSpaceId : undefined,
    };
};
/**
 * The ring this client shows: the newest one still inside its deadline and not already handled here (answered,
 * declined or let go in this client).
 */
export const pickRingingAgentVoiceCall = (rings, nowMs, handledCallIds) => rings
    .filter((ring) => ring.ringExpiresAtUnixMs > nowMs && !handledCallIds.has(ring.callId))
    .sort((left, right) => right.callId - left.callId)[0];
