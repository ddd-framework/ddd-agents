import { readAgentVoiceErrorCode } from "../agent-voice-api";
import { readBoundAgentVoiceApi, startAgentVoiceCall } from "../call/agent-voice-call-store";
import { textKey } from "../../provider/text-key";
export const AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS = {
    answeredElsewhere: textKey("You already answered %agentName% on another device."),
    ringEnded: textKey("%agentName% stopped asking to talk."),
    failed: textKey("The voice chat could not start. Please try again."),
};
/**
 * Answering the agent's ring in the browser: `answerRing` with the ring's single-use token (the atomic first-wins
 * claim: two clients pressing at once get exactly one winner), then the same WebRTC start as the chat's voice button
 * with the call id, so `openSession` attaches to the claimed call. Returns undefined when this browser won and the
 * call is starting.
 */
export const answerIncomingAgentVoiceCall = async (ring) => {
    const api = readBoundAgentVoiceApi();
    if (!api)
        return "failed";
    let conversationIdOfAnswer;
    try {
        const ringAnswer = await api.answerRing({ callId: ring.callId, answerToken: ring.answerToken });
        if (!ringAnswer.won)
            return "answeredElsewhere";
        conversationIdOfAnswer = ringAnswer.conversationId;
    }
    catch (answerError) {
        const errorCode = readAgentVoiceErrorCode(answerError);
        if (errorCode === "already_answered")
            return "answeredElsewhere";
        if (errorCode === "ring_expired" || errorCode === "call_not_found")
            return "ringEnded";
        return "failed";
    }
    void startAgentVoiceCall({
        agentSpaceId: ring.agentSpaceId,
        conversationId: conversationIdOfAnswer ?? ring.conversationId ?? undefined,
        callId: ring.callId,
    });
    return undefined;
};
/** Decline in this browser: ends the ring for every open client of the account. */
export const declineIncomingAgentVoiceCall = (ring) => {
    const api = readBoundAgentVoiceApi();
    if (!api)
        return Promise.resolve();
    return api.declineRing({ callId: ring.callId, answerToken: ring.answerToken }).catch(() => undefined);
};
