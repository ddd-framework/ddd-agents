import { describe, expect, it } from "vitest";
import { AGENT_VOICE_RING_MESSAGE_TYPE, pickRingingAgentVoiceCall, readAgentVoiceRingOfRealtimeMessage } from "./agent-voice-ring";
/** The payload the backend writes (contract section 6): no agent space at the top, the caller context as an object. */
const ringPayload = {
    callId: 8815,
    conversationId: 49167,
    answerToken: "1767259200.signature",
    ringExpiresAt: "2026-01-01T09:20:00.000Z",
    ringExpiresAtUnixMs: 1767259200000,
    callerLabelKey: "%agentName% wants to talk",
    callerContext: { spaceName: "North shop" },
};
const ringMessage = (payload, messageType = AGENT_VOICE_RING_MESSAGE_TYPE) => ({
    messageType,
    messageKey: "8815",
    payloadJson: typeof payload === "string" ? payload : JSON.stringify(payload),
    secondsLeft: 30,
});
const buildRing = (callId, ringExpiresAtUnixMs) => ({
    callId,
    conversationId: null,
    answerToken: `${ringExpiresAtUnixMs}.signature-${callId}`,
    ringExpiresAt: "",
    ringExpiresAtUnixMs,
    callerLabelKey: "%agentName% wants to talk",
    callerContext: {},
});
describe("readAgentVoiceRingOfRealtimeMessage", () => {
    it("names the message type agent.voice.ring", () => {
        expect(AGENT_VOICE_RING_MESSAGE_TYPE).toBe("agent.voice.ring");
    });
    it("reads the payload of section 6 into one ring", () => {
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage(ringPayload))).toStrictEqual({ ...ringPayload, agentSpaceId: undefined });
    });
    it("takes the agent space when the payload names it at the top level", () => {
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...ringPayload, agentSpaceId: 3 }))?.agentSpaceId).toBe(3);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...ringPayload, agentSpaceId: "3" }))?.agentSpaceId).toBe(undefined);
    });
    it("fills the optional fields with their empty values when they are missing or of another type", () => {
        const ring = readAgentVoiceRingOfRealtimeMessage(ringMessage({ callId: 1, answerToken: "token", ringExpiresAtUnixMs: 5000, conversationId: "41", callerLabelKey: 7, callerContext: [] }));
        expect(ring).toStrictEqual({
            callId: 1,
            conversationId: null,
            answerToken: "token",
            ringExpiresAt: "",
            ringExpiresAtUnixMs: 5000,
            callerLabelKey: "",
            callerContext: {},
            agentSpaceId: undefined,
        });
    });
    it("reads no ring from a message of another type", () => {
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage(ringPayload, "agent.client.tool"))).toBe(null);
    });
    it("reads no ring from a payload without a call id, an answer token or a deadline, or one that is no object", () => {
        const { callId, answerToken, ringExpiresAtUnixMs, ...rest } = ringPayload;
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...rest, answerToken, ringExpiresAtUnixMs }))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...rest, callId, ringExpiresAtUnixMs }))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...rest, callId, answerToken }))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage({ ...ringPayload, callId: "8815" }))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage("not json"))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage("[1,2]"))).toBe(null);
        expect(readAgentVoiceRingOfRealtimeMessage(ringMessage("null"))).toBe(null);
    });
});
describe("pickRingingAgentVoiceCall", () => {
    it("shows nothing while nothing rings", () => {
        expect(pickRingingAgentVoiceCall([], 1000, new Set())).toBe(undefined);
    });
    it("shows the newest ring still inside its deadline", () => {
        expect(pickRingingAgentVoiceCall([buildRing(8816, 5000), buildRing(8815, 5000)], 1000, new Set())?.callId).toBe(8816);
        expect(pickRingingAgentVoiceCall([buildRing(8815, 5000), buildRing(8816, 5000)], 1000, new Set())?.callId).toBe(8816);
    });
    it("drops a ring at and past its deadline on the local clock", () => {
        expect(pickRingingAgentVoiceCall([buildRing(8815, 5000)], 4999, new Set())?.callId).toBe(8815);
        expect(pickRingingAgentVoiceCall([buildRing(8815, 5000)], 5000, new Set())).toBe(undefined);
        expect(pickRingingAgentVoiceCall([buildRing(8816, 5000), buildRing(8815, 9000)], 6000, new Set())?.callId).toBe(8815);
    });
    it("never shows again a ring this client already answered, declined or let go", () => {
        expect(pickRingingAgentVoiceCall([buildRing(8815, 5000), buildRing(8816, 5000)], 1000, new Set([8816]))?.callId).toBe(8815);
        expect(pickRingingAgentVoiceCall([buildRing(8815, 5000)], 1000, new Set([8815]))).toBe(undefined);
    });
    it("leaves the list it was given in its order", () => {
        const rings = [buildRing(8815, 5000), buildRing(8816, 5000)];
        pickRingingAgentVoiceCall(rings, 1000, new Set());
        expect(rings.map(ring => ring.callId)).toStrictEqual([8815, 8816]);
    });
});
