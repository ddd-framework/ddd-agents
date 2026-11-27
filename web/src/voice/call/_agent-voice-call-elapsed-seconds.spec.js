import { describe, expect, it } from "vitest";
import { agentVoiceCallElapsedSecondsAt } from "./agent-voice-call-elapsed-seconds";
const CONNECTED_AT_MS = 1767259200000;
describe("agentVoiceCallElapsedSecondsAt", () => {
    it("is 0 before the call connected, whatever the clock or the end say", () => {
        expect(agentVoiceCallElapsedSecondsAt({}, CONNECTED_AT_MS + 90000)).toBe(0);
        expect(agentVoiceCallElapsedSecondsAt({ endedAtMs: CONNECTED_AT_MS + 90000 }, CONNECTED_AT_MS + 90000)).toBe(0);
    });
    it("counts whole seconds from the connect to now while the call runs", () => {
        expect(agentVoiceCallElapsedSecondsAt({ connectedAtMs: CONNECTED_AT_MS }, CONNECTED_AT_MS)).toBe(0);
        expect(agentVoiceCallElapsedSecondsAt({ connectedAtMs: CONNECTED_AT_MS }, CONNECTED_AT_MS + 999)).toBe(0);
        expect(agentVoiceCallElapsedSecondsAt({ connectedAtMs: CONNECTED_AT_MS }, CONNECTED_AT_MS + 1000)).toBe(1);
        expect(agentVoiceCallElapsedSecondsAt({ connectedAtMs: CONNECTED_AT_MS }, CONNECTED_AT_MS + 61999)).toBe(61);
    });
    it("freezes at the end of an ended call, the clock no longer counting", () => {
        const endedCall = { connectedAtMs: CONNECTED_AT_MS, endedAtMs: CONNECTED_AT_MS + 3500 };
        expect(agentVoiceCallElapsedSecondsAt(endedCall, CONNECTED_AT_MS + 600000)).toBe(3);
    });
    it("never goes below 0 for a clock read before the connect moment", () => {
        expect(agentVoiceCallElapsedSecondsAt({ connectedAtMs: CONNECTED_AT_MS }, CONNECTED_AT_MS - 5000)).toBe(0);
    });
});
