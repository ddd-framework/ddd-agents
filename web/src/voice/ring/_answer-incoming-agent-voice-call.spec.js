import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AgentVoiceApiError } from "../agent-voice-api";
import { flushPromises, installFakeBrowser, RecordingAgentVoiceApi } from "../call/agent-voice-call-store.spec-helper";
// The store modules import React at runtime; the stand-in of the spec helper replaces it.
vi.mock("react", async () => (await import("../call/agent-voice-call-store.spec-helper")).createFakeReactModule());
// The fake browser is in place before the call store loads.
const browser = installFakeBrowser();
const store = await import("../call/agent-voice-call-store");
const { AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS, answerIncomingAgentVoiceCall, declineIncomingAgentVoiceCall } = await import("./answer-incoming-agent-voice-call");
const ring = {
    callId: 8815,
    conversationId: 40,
    answerToken: "1767259200.signature",
    ringExpiresAt: "2026-01-01T09:20:00.000Z",
    ringExpiresAtUnixMs: 1767259200000,
    callerLabelKey: "%agentName% wants to talk",
    callerContext: {},
    agentSpaceId: 3,
};
let api;
beforeEach(() => {
    vi.spyOn(console, "info").mockImplementation(() => undefined);
    vi.spyOn(console, "warn").mockImplementation(() => undefined);
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "setInterval", "clearInterval", "Date"], now: 1767259000000 });
});
afterEach(async () => {
    store.hangUpAgentVoiceCall();
    store.dismissAgentVoiceCallOutcome();
    await flushPromises();
    vi.useRealTimers();
    vi.restoreAllMocks();
});
describe("before an adapter is bound", () => {
    it("answers failed and declines without a request", async () => {
        expect(await answerIncomingAgentVoiceCall(ring)).toBe("failed");
        expect(await declineIncomingAgentVoiceCall(ring)).toBe(undefined);
        expect(browser.microphoneRequests.length).toBe(0);
    });
});
describe("answerIncomingAgentVoiceCall", () => {
    beforeEach(() => {
        api = new RecordingAgentVoiceApi();
        store.bindAgentVoiceApi(api);
    });
    it("claims the ring with its token and, having won, opens the session with the call id in the ring’s space", async () => {
        api.ringAnswers.push(async () => ({ won: true, conversationId: 41 }));
        expect(await answerIncomingAgentVoiceCall(ring)).toBe(undefined);
        expect(api.callsOf("answerRing")).toStrictEqual([{ callId: 8815, answerToken: "1767259200.signature" }]);
        await flushPromises();
        expect(api.callsOf("openSession")).toStrictEqual([{ agentSpaceId: 3, sdpOffer: "v=0 fake offer", conversationId: 41, callId: 8815 }]);
        expect(store.readAgentVoiceCallSnapshot().phase).toBe("connecting");
    });
    it("opens on the ring’s conversation when the answer names none, and on none when neither does", async () => {
        api.ringAnswers.push(async () => ({ won: true }));
        await answerIncomingAgentVoiceCall(ring);
        await flushPromises();
        expect(api.callsOf("openSession")[0].conversationId).toBe(40);
        store.hangUpAgentVoiceCall();
        store.dismissAgentVoiceCallOutcome();
        api.ringAnswers.push(async () => ({ won: true }));
        await answerIncomingAgentVoiceCall({ ...ring, conversationId: null });
        await flushPromises();
        expect(api.callsOf("openSession")[1].conversationId).toBe(undefined);
    });
    const quietEnds = [
        ["an answer that lost", undefined, "answeredElsewhere"],
        ["409 already_answered", new AgentVoiceApiError("already_answered", 409), "answeredElsewhere"],
        ["410 ring_expired", new AgentVoiceApiError("ring_expired", 410), "ringEnded"],
        ["404 call_not_found", new AgentVoiceApiError("call_not_found", 404), "ringEnded"],
        ["400 invalid_answer_token", new AgentVoiceApiError("invalid_answer_token", 400), "failed"],
        ["a network failure", new TypeError("Failed to fetch"), "failed"],
    ];
    for (const [situation, failure, refusal] of quietEnds) {
        it(`ends quietly after ${situation}: ${refusal}, no call, no request beyond the answer`, async () => {
            api.ringAnswers.push(async () => {
                if (failure !== undefined)
                    throw failure;
                return { won: false };
            });
            expect(await answerIncomingAgentVoiceCall(ring)).toBe(refusal);
            await flushPromises();
            expect(api.calls.map(call => call.operation)).toStrictEqual(["answerRing"]);
            expect(store.readAgentVoiceCallSnapshot()).toBe(store.IDLE_AGENT_VOICE_CALL);
        });
    }
    it("words each refusal with a key of its own", () => {
        expect(AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS).toStrictEqual({
            answeredElsewhere: "You already answered %agentName% on another device.",
            ringEnded: "%agentName% stopped asking to talk.",
            failed: "The voice chat could not start. Please try again.",
        });
    });
});
describe("declineIncomingAgentVoiceCall", () => {
    beforeEach(() => {
        api = new RecordingAgentVoiceApi();
        store.bindAgentVoiceApi(api);
    });
    it("declines with the ring’s token", async () => {
        await declineIncomingAgentVoiceCall(ring);
        expect(api.callsOf("declineRing")).toStrictEqual([{ callId: 8815, answerToken: "1767259200.signature" }]);
    });
    it("swallows a refused decline", async () => {
        api.declineRing = async () => {
            throw new AgentVoiceApiError("ring_expired", 410);
        };
        expect(await declineIncomingAgentVoiceCall(ring)).toBe(undefined);
    });
});
