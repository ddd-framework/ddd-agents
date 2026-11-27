import { describe, expect, it } from "vitest";
import { turnOfAgentVoiceCall } from "./agent-voice-call-turn";
const liveCall = {
    phase: "live",
    callId: 9,
    isMuted: false,
    isMicrophoneSilent: false,
    isAgentSpeaking: false,
    isOwnerSpeaking: false,
    lines: [],
};
describe("turnOfAgentVoiceCall", () => {
    it("is connecting while the call connects, whatever else is set", () => {
        expect(turnOfAgentVoiceCall({ ...liveCall, phase: "connecting", isMuted: true, isAgentSpeaking: true })).toBe("connecting");
    });
    it("puts muted before a silent microphone, a silent microphone before speech", () => {
        expect(turnOfAgentVoiceCall({ ...liveCall, isMuted: true, isMicrophoneSilent: true, isAgentSpeaking: true })).toBe("muted");
        expect(turnOfAgentVoiceCall({ ...liveCall, isMicrophoneSilent: true, isAgentSpeaking: true })).toBe("silentMicrophone");
    });
    it("names the agent speaking before the owner speaking", () => {
        expect(turnOfAgentVoiceCall({ ...liveCall, isAgentSpeaking: true, isOwnerSpeaking: true })).toBe("agent");
        expect(turnOfAgentVoiceCall({ ...liveCall, isOwnerSpeaking: true, lastSpeaker: "agent" })).toBe("owner");
    });
    it("is thinking when the owner spoke last and nobody speaks now", () => {
        expect(turnOfAgentVoiceCall({ ...liveCall, lastSpeaker: "owner" })).toBe("thinking");
    });
    it("is listening when nobody spoke yet or the agent spoke last", () => {
        expect(turnOfAgentVoiceCall(liveCall)).toBe("listening");
        expect(turnOfAgentVoiceCall({ ...liveCall, lastSpeaker: "agent" })).toBe("listening");
    });
});
