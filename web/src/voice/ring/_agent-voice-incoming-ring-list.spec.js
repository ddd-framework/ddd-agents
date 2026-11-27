import { describe, expect, it } from "vitest";
import { agentVoiceIncomingRingsInDeadlineAt, withAgentVoiceIncomingRing, withoutAgentVoiceIncomingRingOfCall } from "./agent-voice-incoming-ring-list";
const buildRing = (callId, ringExpiresAtUnixMs, answerToken = `token-${callId}`) => ({
    callId,
    conversationId: 40,
    answerToken,
    ringExpiresAt: "",
    ringExpiresAtUnixMs,
    callerLabelKey: "%agentName% wants to talk",
    callerContext: {},
});
const callIdsAndTokens = (rings) => rings.map(ring => [ring.callId, ring.answerToken]);
describe("withAgentVoiceIncomingRing", () => {
    it("adds a ring of a new call at the end", () => {
        const heldRings = [buildRing(1, 5000)];
        expect(callIdsAndTokens(withAgentVoiceIncomingRing(heldRings, buildRing(2, 5000)))).toStrictEqual([
            [1, "token-1"],
            [2, "token-2"],
        ]);
    });
    it("keeps one ring per call: a replaced ring drops its predecessor and goes to the end", () => {
        const heldRings = [buildRing(1, 5000, "first"), buildRing(2, 5000)];
        expect(callIdsAndTokens(withAgentVoiceIncomingRing(heldRings, buildRing(1, 9000, "replaced")))).toStrictEqual([
            [2, "token-2"],
            [1, "replaced"],
        ]);
    });
    it("leaves the held list as it was", () => {
        const heldRings = [buildRing(1, 5000, "first")];
        withAgentVoiceIncomingRing(heldRings, buildRing(1, 9000, "replaced"));
        expect(callIdsAndTokens(heldRings)).toStrictEqual([[1, "first"]]);
    });
});
describe("withoutAgentVoiceIncomingRingOfCall", () => {
    it("drops the cleared ring at once and keeps the others in their order", () => {
        const heldRings = [buildRing(1, 5000), buildRing(2, 5000), buildRing(3, 5000)];
        expect(callIdsAndTokens(withoutAgentVoiceIncomingRingOfCall(heldRings, 2))).toStrictEqual([
            [1, "token-1"],
            [3, "token-3"],
        ]);
        expect(heldRings.length).toBe(3);
    });
    it("changes nothing for a call that does not ring", () => {
        const heldRings = [buildRing(1, 5000)];
        expect(callIdsAndTokens(withoutAgentVoiceIncomingRingOfCall(heldRings, 9))).toStrictEqual([[1, "token-1"]]);
    });
});
describe("agentVoiceIncomingRingsInDeadlineAt", () => {
    it("drops a ring at and past its deadline", () => {
        const heldRings = [buildRing(1, 5000), buildRing(2, 6000), buildRing(3, 7000)];
        expect(agentVoiceIncomingRingsInDeadlineAt(heldRings, 6000).map(ring => ring.callId)).toStrictEqual([3]);
        expect(agentVoiceIncomingRingsInDeadlineAt(heldRings, 5999).map(ring => ring.callId)).toStrictEqual([2, 3]);
        expect(heldRings.length).toBe(3);
    });
    it("answers the same list when nothing passed its deadline, a new one when something did", () => {
        const heldRings = [buildRing(1, 5000), buildRing(2, 6000)];
        expect(agentVoiceIncomingRingsInDeadlineAt(heldRings, 4999)).toBe(heldRings);
        expect(agentVoiceIncomingRingsInDeadlineAt(heldRings, 5000)).not.toBe(heldRings);
        const noRings = [];
        expect(agentVoiceIncomingRingsInDeadlineAt(noRings, 1)).toBe(noRings);
    });
});
