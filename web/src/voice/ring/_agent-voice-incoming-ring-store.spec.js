import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AgentVoiceApiError } from "../agent-voice-api";
import { countNotifications, createDeferred, flushPromises, installFakeBrowser, readStoreHandle, RecordingAgentVoiceApi, } from "../call/agent-voice-call-store.spec-helper";
// The store modules import React at runtime; the stand-in of the spec helper replaces it.
vi.mock("react", async () => (await import("../call/agent-voice-call-store.spec-helper")).createFakeReactModule());
// The fake browser is in place before the stores load.
installFakeBrowser();
const callStore = await import("../call/agent-voice-call-store");
const ringStore = await import("./agent-voice-incoming-ring-store");
const buildRing = (callId, ringExpiresAtUnixMs = 1767259200000) => ({
    callId,
    conversationId: 40,
    answerToken: `token-${callId}`,
    ringExpiresAt: "",
    ringExpiresAtUnixMs,
    callerLabelKey: "%agentName% wants to talk",
    callerContext: {},
});
const callerLabel = { text: "Sam wants to talk" };
const ringState = () => ringStore.readAgentVoiceIncomingRingSnapshot();
let api;
beforeEach(() => {
    vi.spyOn(console, "info").mockImplementation(() => undefined);
    vi.spyOn(console, "warn").mockImplementation(() => undefined);
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "setInterval", "clearInterval", "Date"], now: 1767259000000 });
    api = new RecordingAgentVoiceApi();
    callStore.bindAgentVoiceApi(api);
});
afterEach(async () => {
    ringStore.publishAgentVoiceIncomingRing(undefined, undefined);
    callStore.hangUpAgentVoiceCall();
    callStore.dismissAgentVoiceCallOutcome();
    await flushPromises();
    vi.advanceTimersByTime(5000);
    vi.useRealTimers();
    vi.restoreAllMocks();
});
describe("pickAgentVoiceIncomingCard", () => {
    it("shows nothing while nothing rings and no Accept was refused", () => {
        expect(ringStore.pickAgentVoiceIncomingCard({ bandHostCount: 0, ringCoverCount: 0 })).toBe(undefined);
    });
    it("shows the ring while no band is in sight, and leaves it to a band that is", () => {
        expect(ringStore.pickAgentVoiceIncomingCard({ ring: buildRing(1), bandHostCount: 0, ringCoverCount: 0 })).toBe("ring");
        expect(ringStore.pickAgentVoiceIncomingCard({ ring: buildRing(1), bandHostCount: 1, ringCoverCount: 0 })).toBe(undefined);
        expect(ringStore.pickAgentVoiceIncomingCard({ ring: buildRing(1), bandHostCount: 2, ringCoverCount: 1 })).toBe("ring");
    });
    it("shows a refused Accept by the same rule, the ring before the refusal", () => {
        expect(ringStore.pickAgentVoiceIncomingCard({ refusal: "failed", bandHostCount: 0, ringCoverCount: 0 })).toBe("refusal");
        expect(ringStore.pickAgentVoiceIncomingCard({ refusal: "failed", bandHostCount: 1, ringCoverCount: 0 })).toBe(undefined);
        expect(ringStore.pickAgentVoiceIncomingCard({ refusal: "failed", bandHostCount: 1, ringCoverCount: 1 })).toBe("refusal");
        expect(ringStore.pickAgentVoiceIncomingCard({ ring: buildRing(1), refusal: "failed", bandHostCount: 0, ringCoverCount: 0 })).toBe("ring");
    });
});
describe("publishAgentVoiceIncomingRing", () => {
    it("holds the published ring with its caller line, and tells subscribers only when the ring or the line changed", () => {
        const counter = countNotifications(readStoreHandle(() => ringStore.useAgentVoiceIncomingRing()));
        const ring = buildRing(101);
        ringStore.publishAgentVoiceIncomingRing(ring, callerLabel);
        expect(ringState().ring).toBe(ring);
        expect(ringState().callerLabel).toStrictEqual(callerLabel);
        expect(counter.count).toBe(1);
        ringStore.publishAgentVoiceIncomingRing({ ...ring }, { ...callerLabel });
        expect(counter.count).toBe(1);
        ringStore.publishAgentVoiceIncomingRing(buildRing(101, 1767259300000), callerLabel);
        expect(counter.count).toBe(2);
        ringStore.publishAgentVoiceIncomingRing(buildRing(101, 1767259300000), { text: "Sam wants to talk", detail: "North shop" });
        expect(counter.count).toBe(3);
        ringStore.publishAgentVoiceIncomingRing(undefined, callerLabel);
        expect(ringState().ring).toBe(undefined);
        expect(ringState().callerLabel).toBe(undefined);
        expect(counter.count).toBe(4);
        counter.stop();
    });
});
describe("accepting a ring", () => {
    it("does nothing without a ring", async () => {
        await ringStore.acceptAgentVoiceIncomingRing();
        expect(api.calls).toStrictEqual([]);
    });
    it("answers, marks the call handled, clears the ring and starts the call on the ring’s call id when this client won", async () => {
        const ringAnswer = createDeferred();
        api.ringAnswers.push(() => ringAnswer.promise);
        ringStore.publishAgentVoiceIncomingRing(buildRing(201), callerLabel);
        const accepting = ringStore.acceptAgentVoiceIncomingRing();
        expect(ringState().isAnswering).toBe(true);
        await ringStore.acceptAgentVoiceIncomingRing();
        expect(api.callsOf("answerRing").length).toBe(1);
        ringAnswer.resolve({ won: true, conversationId: 41 });
        await accepting;
        expect(ringState().isAnswering).toBe(false);
        expect(ringState().ring).toBe(undefined);
        expect(ringState().callerLabel).toBe(undefined);
        expect(ringState().refusal).toBe(undefined);
        expect(ringState().handledCallIds.has(201)).toBe(true);
        await flushPromises();
        expect(api.callsOf("openSession")[0].callId).toStrictEqual(201);
    });
    it("shows why a lost or ended ring did not get the call for four seconds, and starts nothing", async () => {
        api.ringAnswers.push(async () => {
            throw new AgentVoiceApiError("already_answered", 409);
        });
        ringStore.publishAgentVoiceIncomingRing(buildRing(301), callerLabel);
        await ringStore.acceptAgentVoiceIncomingRing();
        expect(ringState().refusal).toBe("answeredElsewhere");
        expect(ringState().ring).toBe(undefined);
        expect(ringState().handledCallIds.has(301)).toBe(true);
        vi.advanceTimersByTime(3999);
        expect(ringState().refusal).toBe("answeredElsewhere");
        vi.advanceTimersByTime(1);
        expect(ringState().refusal).toBe(undefined);
        expect(api.callsOf("openSession")).toStrictEqual([]);
        api.ringAnswers.push(async () => {
            throw new AgentVoiceApiError("ring_expired", 410);
        });
        ringStore.publishAgentVoiceIncomingRing(buildRing(302), callerLabel);
        await ringStore.acceptAgentVoiceIncomingRing();
        expect(ringState().refusal).toBe("ringEnded");
        expect(api.callsOf("openSession")).toStrictEqual([]);
    });
    it("keeps a newer ring the listener published while the answer was in flight", async () => {
        const ringAnswer = createDeferred();
        api.ringAnswers.push(() => ringAnswer.promise);
        ringStore.publishAgentVoiceIncomingRing(buildRing(401), callerLabel);
        const accepting = ringStore.acceptAgentVoiceIncomingRing();
        const newerRing = buildRing(402);
        ringStore.publishAgentVoiceIncomingRing(newerRing, { text: "Sam wants to talk again" });
        ringAnswer.resolve({ won: false });
        await accepting;
        expect(ringState().ring).toBe(newerRing);
        expect(ringState().callerLabel).toStrictEqual({ text: "Sam wants to talk again" });
        expect(ringState().handledCallIds.has(401)).toBe(true);
        expect(ringState().handledCallIds.has(402)).toBe(false);
    });
});
describe("declining a ring", () => {
    it("marks the call handled, clears the ring and declines with its token", async () => {
        ringStore.publishAgentVoiceIncomingRing(buildRing(501), callerLabel);
        const handledCallIdsBefore = ringState().handledCallIds;
        ringStore.declineAgentVoiceIncomingRing();
        expect(ringState().ring).toBe(undefined);
        expect(ringState().handledCallIds.has(501)).toBe(true);
        expect(ringState().handledCallIds).not.toBe(handledCallIdsBefore);
        expect(handledCallIdsBefore.has(501)).toBe(false);
        await flushPromises();
        expect(api.callsOf("declineRing")).toStrictEqual([{ callId: 501, answerToken: "token-501" }]);
    });
    it("does nothing while an answer is in flight, or without a ring", async () => {
        const ringAnswer = createDeferred();
        api.ringAnswers.push(() => ringAnswer.promise);
        ringStore.publishAgentVoiceIncomingRing(buildRing(601), callerLabel);
        const accepting = ringStore.acceptAgentVoiceIncomingRing();
        ringStore.declineAgentVoiceIncomingRing();
        expect(ringState().handledCallIds.has(601)).toBe(false);
        ringAnswer.resolve({ won: false });
        await accepting;
        ringStore.declineAgentVoiceIncomingRing();
        await flushPromises();
        expect(api.callsOf("declineRing")).toStrictEqual([]);
    });
});
