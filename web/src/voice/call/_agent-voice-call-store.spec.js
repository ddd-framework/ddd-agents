import { readFileSync } from "node:fs";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AgentVoiceApiError } from "../agent-voice-api";
import { countNotifications, createDeferred, FakeAudioElement, FakePeerConnection, flushPromises, installFakeBrowser, readStoreHandle, RecordingAgentVoiceApi, } from "./agent-voice-call-store.spec-helper";
// The store modules import React at runtime; the stand-in of the spec helper replaces it.
vi.mock("react", async () => (await import("./agent-voice-call-store.spec-helper")).createFakeReactModule());
// The fake browser is in place before the store loads.
const browser = installFakeBrowser();
const store = await import("./agent-voice-call-store");
const { turnOfAgentVoiceCall } = await import("./agent-voice-call-turn");
const START_MS = 1767259200000;
const SESSION_CLOSE_MESSAGE = '{"type":"session.close"}';
const CONNECTION_DROPPED_TEXT_KEY = "The connection to %agentName% dropped. Please try again.";
const GENERIC_OPEN_FAILURE_TEXT_KEY = "The voice chat could not be started. Please try again.";
const manifestKeys = new Set(JSON.parse(readFileSync(join(__dirname, "..", "..", "i18n-keys.json"), "utf8")).keys.map(entry => entry.key));
/** Every failure sentence the store hands out reaches the application's text function: it must be a manifest key. */
const assertFailureTextKey = (expectedTextKey) => {
    expect(snapshot().failureTextKey).toBe(expectedTextKey);
    expect(manifestKeys.has(expectedTextKey), `not in i18n-keys.json: ${expectedTextKey}`).toBe(true);
};
const snapshot = () => store.readAgentVoiceCallSnapshot();
const peerConnection = () => browser.lastPeerConnection();
const providerChannel = () => {
    const channel = peerConnection().dataChannel;
    if (channel === undefined)
        throw new Error("no data channel was created");
    return channel;
};
const microphoneTrack = () => browser.microphoneStreams[browser.microphoneStreams.length - 1].tracks[0];
/** One tick of the store's one-second timers, then the promise reactions they started. */
const advance = async (milliseconds) => {
    vi.advanceTimersByTime(milliseconds);
    await flushPromises();
};
const startLiveCall = async (params = {}) => {
    await store.startAgentVoiceCall(params);
    providerChannel().deliverProviderEvent({ type: "session.started" });
};
let api;
beforeEach(() => {
    vi.spyOn(console, "info").mockImplementation(() => undefined);
    vi.spyOn(console, "warn").mockImplementation(() => undefined);
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "setInterval", "clearInterval", "Date"], now: START_MS });
    browser.microphoneAnswer = "granted";
});
afterEach(async () => {
    store.hangUpAgentVoiceCall();
    store.dismissAgentVoiceCallOutcome();
    await flushPromises();
    vi.useRealTimers();
    vi.restoreAllMocks();
});
describe("before an application bound an adapter", () => {
    it("starts nothing: no microphone, no peer connection, still idle", async () => {
        await store.startAgentVoiceCall({ conversationId: 40 });
        expect(store.readBoundAgentVoiceApi()).toBe(undefined);
        expect(snapshot()).toBe(store.IDLE_AGENT_VOICE_CALL);
        expect(browser.microphoneRequests.length).toBe(0);
        expect(FakePeerConnection.instances.length).toBe(0);
    });
});
describe("the call store", () => {
    beforeEach(() => {
        api = new RecordingAgentVoiceApi();
        store.bindAgentVoiceApi(api);
    });
    describe("phases", () => {
        it("reads connecting and live as active, idle, ended and failed as not", () => {
            expect(["idle", "connecting", "live", "ended", "failed"].map(phase => store.isAgentVoiceCallActive(phase))).toStrictEqual([false, true, true, false, false]);
        });
        it("keeps the bound adapter readable for answering a ring", () => {
            expect(store.readBoundAgentVoiceApi()).toBe(api);
        });
        it("is connecting with the given space, conversation and call while the microphone, the offer and open run", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall({ agentSpaceId: 3, conversationId: 40 });
            await flushPromises();
            expect(snapshot()).toStrictEqual({ ...store.IDLE_AGENT_VOICE_CALL, phase: "connecting", agentSpaceId: 3, conversationId: 40, callId: undefined });
            expect(browser.microphoneRequests[0]).toStrictEqual({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
            expect(peerConnection().addedTracks).toStrictEqual(browser.microphoneStreams[0].tracks);
            expect(providerChannel().label).toBe("oai-events");
            expect(FakeAudioElement.instances[FakeAudioElement.instances.length - 1].autoplay).toBe(true);
            expect(api.callsOf("openSession")).toStrictEqual([{ agentSpaceId: 3, sdpOffer: "v=0 fake offer", conversationId: 40, callId: undefined }]);
            openAnswer.resolve({ callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 });
            await starting;
            expect(snapshot().phase).toBe("connecting");
            expect(snapshot().callId).toBe(9);
            expect(snapshot().conversationId).toBe(41);
            expect(peerConnection().remoteDescription).toStrictEqual({ type: "answer", sdp: "v=0 fake answer" });
            expect(api.callsOf("reportDeviceStatus").map(args => [args.callId, args.status, args.occurredAtUnixMs])).toStrictEqual([[9, "ANSWER_APPLIED", START_MS]]);
        });
        it("goes live on session.started, once", async () => {
            await store.startAgentVoiceCall();
            await advance(2000);
            providerChannel().deliverProviderEvent({ type: "session.started" });
            expect(snapshot().phase).toBe("live");
            expect(snapshot().connectedAtMs).toBe(START_MS + 2000);
            expect(api.reportedStatuses().filter(status => status === "SESSION_STARTED_SEEN").length).toBe(1);
            await advance(1000);
            providerChannel().deliverProviderEvent({ type: "session.started" });
            expect(snapshot().connectedAtMs).toBe(START_MS + 2000);
            expect(api.reportedStatuses().filter(status => status === "SESSION_STARTED_SEEN").length).toBe(1);
        });
        it("fails when session.started does not come within 15 seconds, and hangs up on the server", async () => {
            await store.startAgentVoiceCall();
            await advance(14999);
            expect(snapshot().phase).toBe("connecting");
            await advance(1);
            expect(snapshot().phase).toBe("failed");
            assertFailureTextKey("%agentName% could not join the voice chat. Please try again.");
            expect(snapshot().endedAtMs).toBe(START_MS + 15000);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "MEDIA_FAILED" }]);
            const mediaFailedReport = api.callsOf("reportDeviceStatus").find(args => args.status === "MEDIA_FAILED");
            expect(mediaFailedReport?.detail).toBe("no session.started within 15s");
            expect(providerChannel().sentMessages).toStrictEqual([SESSION_CLOSE_MESSAGE]);
            expect(api.calls.slice(-2).map(call => call.operation)).toStrictEqual(["reportDeviceStatus", "hangUp"]);
        });
        it("keeps a live call when the 15 seconds pass after session.started", async () => {
            await startLiveCall();
            await advance(20000);
            expect(snapshot().phase).toBe("live");
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("releases the microphone, the peer connection, the channel and the audio when the call ends", async () => {
            await startLiveCall();
            store.hangUpAgentVoiceCall();
            expect(microphoneTrack().stopped).toBe(true);
            expect(peerConnection().closed).toBe(true);
            expect(providerChannel().closed).toBe(true);
            const audioElement = FakeAudioElement.instances[FakeAudioElement.instances.length - 1];
            expect(audioElement.paused).toBe(true);
            expect(audioElement.srcObject).toBe(null);
        });
        it("fails with an unreachable voice when open answers no SDP, and hangs the opened call up", async () => {
            api.defaultOpenSessionAnswer = { callId: 9, sdpAnswer: "", conversationId: 41 };
            await store.startAgentVoiceCall();
            expect(snapshot().phase).toBe("failed");
            assertFailureTextKey("The voice of %agentName% is not reachable right now. Please try again in a minute.");
            expect(snapshot().callId).toBe(9);
            expect(snapshot().conversationId).toBe(41);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "MEDIA_FAILED" }]);
            expect(peerConnection().remoteDescription).toBe(null);
        });
    });
    describe("a refused open", () => {
        const textKeysByCode = [
            ["feature_not_available", 403, "Voice chats with %agentName% are not available here."],
            ["no_ai_budget", 403, "Your AI budget is used up, so %agentName% cannot talk right now."],
            ["daily_limit_reached", 403, "The time for voice chats with %agentName% is used up for today. Please try again tomorrow."],
            ["call_already_running", 409, "You are already in a voice chat with %agentName%. End it first, then start a new one."],
            ["conversation_not_found", 404, "This conversation cannot take a voice chat. Start a new chat and talk to %agentName% from there."],
            ["provider_unavailable", 502, "The voice of %agentName% is not reachable right now. Please try again in a minute."],
            ["invalid_sdp", 400, "Your browser could not set up the voice chat. Please try again."],
        ];
        for (const [code, httpStatus, textKey] of textKeysByCode) {
            it(`leaves a failed call with the sentence of ${code}`, async () => {
                api.openSessionAnswers.push(async () => {
                    throw new AgentVoiceApiError(code, httpStatus);
                });
                await store.startAgentVoiceCall({ agentSpaceId: 3, conversationId: 40 });
                expect(snapshot()).toStrictEqual({
                    ...store.IDLE_AGENT_VOICE_CALL,
                    phase: "failed",
                    agentSpaceId: 3,
                    conversationId: 40,
                    callId: undefined,
                    failureTextKey: textKey,
                    endedAtMs: START_MS,
                });
                assertFailureTextKey(textKey);
                expect(api.callsOf("hangUp")).toStrictEqual([]);
                expect(microphoneTrack().stopped).toBe(true);
                expect(peerConnection().closed).toBe(true);
            });
        }
        it("reads the code wherever the request layer put it (an error body, a body below a status)", async () => {
            const failures = [{ error: "daily_limit_reached" }, { status: 403, data: { error: "daily_limit_reached" } }];
            for (const failure of failures) {
                api.openSessionAnswers.push(async () => {
                    throw failure;
                });
                await store.startAgentVoiceCall();
                assertFailureTextKey("The time for voice chats with %agentName% is used up for today. Please try again tomorrow.");
                store.dismissAgentVoiceCallOutcome();
            }
        });
        it("shows the general sentence for a code it has none for, a prototype name, and a failure without a code", async () => {
            const failures = [new AgentVoiceApiError("call_not_answered", 400), new AgentVoiceApiError("toString"), new TypeError("Failed to fetch"), {}];
            for (const failure of failures) {
                api.openSessionAnswers.push(async () => {
                    throw failure;
                });
                await store.startAgentVoiceCall();
                expect(snapshot().phase).toBe("failed");
                assertFailureTextKey(GENERIC_OPEN_FAILURE_TEXT_KEY);
                store.dismissAgentVoiceCallOutcome();
            }
        });
        it("hangs up the answered call it could not open, and keeps its id", async () => {
            api.openSessionAnswers.push(async () => {
                throw new AgentVoiceApiError("provider_unavailable", 502);
            });
            await store.startAgentVoiceCall({ callId: 7, conversationId: 40 });
            expect(snapshot().phase).toBe("failed");
            expect(snapshot().callId).toBe(7);
            expect(api.callsOf("openSession")[0].callId).toStrictEqual(7);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 7, reason: "MEDIA_FAILED" }]);
        });
    });
    describe("the microphone refused", () => {
        it("fails with the microphone sentence before any peer connection or open", async () => {
            browser.microphoneAnswer = "denied";
            const peerConnectionsBefore = FakePeerConnection.instances.length;
            await store.startAgentVoiceCall({ conversationId: 40 });
            expect(snapshot().phase).toBe("failed");
            assertFailureTextKey("%agentName% needs your microphone. Allow microphone access in your browser, then try again.");
            expect(FakePeerConnection.instances.length).toBe(peerConnectionsBefore);
            expect(api.callsOf("openSession")).toStrictEqual([]);
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("hangs up an answered call on the server", async () => {
            browser.microphoneAnswer = "denied";
            await store.startAgentVoiceCall({ callId: 7 });
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 7, reason: "MEDIA_FAILED" }]);
        });
    });
    describe("one call at a time", () => {
        it("ignores a start while a call connects", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall({ conversationId: 40 });
            await flushPromises();
            const microphoneRequestsBefore = browser.microphoneRequests.length;
            await store.startAgentVoiceCall({ conversationId: 99 });
            expect(browser.microphoneRequests.length).toBe(microphoneRequestsBefore);
            expect(api.callsOf("openSession").length).toBe(1);
            expect(snapshot().conversationId).toBe(40);
            openAnswer.resolve({ callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 });
            await starting;
        });
        it("ignores a start while a call is live", async () => {
            await startLiveCall({ conversationId: 40 });
            await store.startAgentVoiceCall({ conversationId: 99 });
            expect(api.callsOf("openSession").length).toBe(1);
            expect(snapshot().phase).toBe("live");
        });
        it("starts afresh after an ended call, without the old lines, mute, end reason or connection time", async () => {
            await startLiveCall();
            providerChannel().deliverProviderEvent({ type: "session.output_transcript.delta", delta: "Hello." });
            store.toggleAgentVoiceCallMute();
            api.callStateAnswers.push(async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "LIMIT_REACHED" }));
            await store.checkAgentVoiceCallEndedOnServer();
            expect(snapshot().endReason).toBe("LIMIT_REACHED");
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall({ conversationId: 50 });
            await flushPromises();
            expect(snapshot()).toStrictEqual({ ...store.IDLE_AGENT_VOICE_CALL, phase: "connecting", agentSpaceId: undefined, conversationId: 50, callId: undefined });
            openAnswer.resolve({ callId: 10, sdpAnswer: "v=0 fake answer", conversationId: 50 });
            await starting;
        });
    });
    describe("hanging up from this browser", () => {
        it("reports the end, closes the session, hangs up and shows an ended call with no server reason", async () => {
            await startLiveCall();
            await advance(3000);
            store.hangUpAgentVoiceCall();
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().endedAtMs).toBe(START_MS + 3000);
            expect(snapshot().endReason).toBe(undefined);
            expect(snapshot().isAgentSpeaking).toBe(false);
            expect(snapshot().isOwnerSpeaking).toBe(false);
            expect(providerChannel().sentMessages).toStrictEqual([SESSION_CLOSE_MESSAGE]);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "USER_ENDED" }]);
            expect(api.calls.slice(-2).map(call => [call.operation, call.args.status])).toStrictEqual([
                ["reportDeviceStatus", "ENDED_BY_USER"],
                ["hangUp", undefined],
            ]);
        });
        it("reports no user end for another reason, and sends no session.close for PROVIDER_CLOSED", async () => {
            await startLiveCall();
            store.hangUpAgentVoiceCall("APP_BACKGROUNDED");
            expect(api.reportedStatuses().includes("ENDED_BY_USER")).toBe(false);
            expect(providerChannel().sentMessages).toStrictEqual([SESSION_CLOSE_MESSAGE]);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "APP_BACKGROUNDED" }]);
            store.dismissAgentVoiceCallOutcome();
            await startLiveCall();
            store.hangUpAgentVoiceCall("PROVIDER_CLOSED");
            expect(providerChannel().sentMessages).toStrictEqual([]);
            expect(api.callsOf("hangUp").slice(-1)).toStrictEqual([{ callId: 9, reason: "PROVIDER_CLOSED" }]);
        });
        it("sends no session.close over a channel that is not open", async () => {
            await startLiveCall();
            providerChannel().readyState = "closing";
            store.hangUpAgentVoiceCall();
            expect(providerChannel().sentMessages).toStrictEqual([]);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "USER_ENDED" }]);
        });
        it("does nothing without a running call", async () => {
            const counter = countNotifications(readStoreHandle(() => store.useAgentVoiceCallSnapshot()));
            store.hangUpAgentVoiceCall();
            counter.stop();
            expect(counter.count).toBe(0);
            expect(api.calls).toStrictEqual([]);
            expect(snapshot()).toBe(store.IDLE_AGENT_VOICE_CALL);
        });
        it("hangs up a call whose open is still in flight once open answers, and keeps the call ended", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall({ conversationId: 40 });
            await flushPromises();
            store.hangUpAgentVoiceCall();
            expect(snapshot().phase).toBe("ended");
            expect(api.callsOf("hangUp")).toStrictEqual([]);
            openAnswer.resolve({ callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 });
            await starting;
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "USER_ENDED" }]);
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().callId).toBe(undefined);
            expect(peerConnection().remoteDescription).toBe(null);
        });
        it("stays ended when an open in flight is refused after the hang-up", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall();
            await flushPromises();
            store.hangUpAgentVoiceCall();
            openAnswer.reject(new AgentVoiceApiError("no_ai_budget", 403));
            await starting;
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().failureTextKey).toBe(undefined);
        });
        it("hangs up with APP_BACKGROUNDED when the page goes away, with one listener for any number of calls", async () => {
            await startLiveCall();
            store.hangUpAgentVoiceCall();
            store.dismissAgentVoiceCallOutcome();
            await startLiveCall();
            expect(browser.pageHideListeners.length).toBe(1);
            browser.pageHideListeners[0]();
            expect(snapshot().phase).toBe("ended");
            expect(api.callsOf("hangUp").slice(-1)).toStrictEqual([{ callId: 9, reason: "APP_BACKGROUNDED" }]);
        });
    });
    describe("mute", () => {
        it("switches the microphone track off and on, and the turn reads muted", async () => {
            await startLiveCall();
            store.toggleAgentVoiceCallMute();
            expect(snapshot().isMuted).toBe(true);
            expect(microphoneTrack().enabled).toBe(false);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("muted");
            store.toggleAgentVoiceCallMute();
            expect(snapshot().isMuted).toBe(false);
            expect(microphoneTrack().enabled).toBe(true);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("listening");
        });
    });
    describe("dismissing an outcome", () => {
        it("puts an ended call and a failed call back to idle", async () => {
            await startLiveCall();
            store.hangUpAgentVoiceCall();
            store.dismissAgentVoiceCallOutcome();
            expect(snapshot()).toBe(store.IDLE_AGENT_VOICE_CALL);
            browser.microphoneAnswer = "denied";
            await store.startAgentVoiceCall();
            expect(snapshot().phase).toBe("failed");
            store.dismissAgentVoiceCallOutcome();
            expect(snapshot()).toBe(store.IDLE_AGENT_VOICE_CALL);
        });
        it("leaves a connecting or live call alone", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall();
            await flushPromises();
            store.dismissAgentVoiceCallOutcome();
            expect(snapshot().phase).toBe("connecting");
            openAnswer.resolve({ callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 });
            await starting;
            providerChannel().deliverProviderEvent({ type: "session.started" });
            store.dismissAgentVoiceCallOutcome();
            expect(snapshot().phase).toBe("live");
        });
    });
    describe("subscribers", () => {
        it("are told once per change: start (connecting, then the opened call), live, mute, hang-up, dismiss", async () => {
            const counter = countNotifications(readStoreHandle(() => store.useAgentVoiceCallSnapshot()));
            await store.startAgentVoiceCall();
            expect(counter.count).toBe(2);
            providerChannel().deliverProviderEvent({ type: "session.started" });
            expect(counter.count).toBe(3);
            store.toggleAgentVoiceCallMute();
            expect(counter.count).toBe(4);
            store.hangUpAgentVoiceCall();
            expect(counter.count).toBe(5);
            store.dismissAgentVoiceCallOutcome();
            expect(counter.count).toBe(6);
            counter.stop();
            store.toggleAgentVoiceCallMute();
            expect(counter.count).toBe(6);
            store.toggleAgentVoiceCallMute();
        });
        it("are not told about an ignored provider event or an empty delta", async () => {
            await startLiveCall();
            const counter = countNotifications(readStoreHandle(() => store.useAgentVoiceCallSnapshot()));
            providerChannel().deliverProviderEvent("not json");
            providerChannel().deliverProviderEvent({ type: "response.done" });
            providerChannel().deliverProviderEvent({ type: "session.output_transcript.delta", delta: "" });
            providerChannel().deliverProviderEvent({ type: "session.input_transcript.delta", delta: 42 });
            counter.stop();
            expect(counter.count).toBe(0);
            expect(snapshot().lines).toStrictEqual([]);
        });
        it("read one field through useAgentVoiceCallValue and whether a chat of a space shows the call", async () => {
            await startLiveCall({ agentSpaceId: 3 });
            expect(store.useAgentVoiceCallValue(call => call.phase)).toBe("live");
            expect(store.useIsAgentVoiceCallActive()).toBe(true);
            expect(store.useIsAgentVoiceCallActive(3)).toBe(true);
            expect(store.useIsAgentVoiceCallActive(4)).toBe(false);
            store.hangUpAgentVoiceCall();
            expect(store.useIsAgentVoiceCallActive(3)).toBe(false);
        });
    });
    describe("isAgentVoiceCallShownInAgentSpace", () => {
        it("holds a call apart only when both the chat and the call name a space and they differ", () => {
            const callInSpace3 = { ...store.IDLE_AGENT_VOICE_CALL, phase: "live", agentSpaceId: 3 };
            const callWithoutSpace = { ...store.IDLE_AGENT_VOICE_CALL, phase: "live" };
            expect(store.isAgentVoiceCallShownInAgentSpace(callInSpace3, 3)).toBe(true);
            expect(store.isAgentVoiceCallShownInAgentSpace(callInSpace3, 4)).toBe(false);
            expect(store.isAgentVoiceCallShownInAgentSpace(callInSpace3, undefined)).toBe(true);
            expect(store.isAgentVoiceCallShownInAgentSpace(callWithoutSpace, 4)).toBe(true);
        });
    });
    describe("what was said", () => {
        it("grows a line per turn of a speaker, trims the start of a new turn, and names the last speaker", async () => {
            await startLiveCall();
            const channel = providerChannel();
            channel.deliverProviderEvent({ type: "session.output_transcript.delta", delta: " Hello" });
            channel.deliverProviderEvent({ type: "session.output_transcript.delta", delta: ", how can I help?" });
            expect(snapshot().lastSpeaker).toBe("agent");
            channel.deliverProviderEvent({ type: "session.input_transcript.delta", delta: "  Move my" });
            channel.deliverProviderEvent({ type: "session.input_transcript.delta", delta: " meeting." });
            expect(snapshot().lastSpeaker).toBe("owner");
            channel.deliverProviderEvent({ type: "session.output_transcript.delta", delta: "Done." });
            expect(snapshot().lines).toStrictEqual([
                { id: 1, speaker: "agent", text: "Hello, how can I help?" },
                { id: 2, speaker: "owner", text: "Move my meeting." },
                { id: 3, speaker: "agent", text: "Done." },
            ]);
        });
        it("keeps the 40 most recent lines, the ids still counting up", async () => {
            await startLiveCall();
            for (let turn = 1; turn <= 41; turn += 1) {
                providerChannel().deliverProviderEvent({
                    type: turn % 2 === 1 ? "session.output_transcript.delta" : "session.input_transcript.delta",
                    delta: `Turn ${turn}.`,
                });
            }
            expect(snapshot().lines.length).toBe(40);
            expect(snapshot().lines[0]).toStrictEqual({ id: 2, speaker: "owner", text: "Turn 2." });
            expect(snapshot().lines[39]).toStrictEqual({ id: 41, speaker: "agent", text: "Turn 41." });
        });
        it("holds a speaker as speaking 900 ms after their last delta", async () => {
            await startLiveCall();
            providerChannel().deliverProviderEvent({ type: "session.output_transcript.delta", delta: "One" });
            expect(snapshot().isAgentSpeaking).toBe(true);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("agent");
            await advance(500);
            providerChannel().deliverProviderEvent({ type: "session.output_transcript.delta", delta: " two" });
            await advance(899);
            expect(snapshot().isAgentSpeaking).toBe(true);
            await advance(1);
            expect(snapshot().isAgentSpeaking).toBe(false);
            providerChannel().deliverProviderEvent({ type: "session.input_transcript.delta", delta: "Yes" });
            expect(snapshot().isOwnerSpeaking).toBe(true);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("owner");
            await advance(899);
            expect(snapshot().isOwnerSpeaking).toBe(true);
            await advance(1);
            expect(snapshot().isOwnerSpeaking).toBe(false);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("thinking");
        });
    });
    describe("an end on the server", () => {
        it("after session.closed ends with the reason of the call, without a hang-up or session.close when the agent ended it", async () => {
            await startLiveCall();
            api.callStateAnswers.push(async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "ENDED_BY_AGENT" }));
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().endReason).toBe("ENDED_BY_AGENT");
            expect(snapshot().endedAtMs).toBe(START_MS);
            expect(api.callsOf("readCallState")).toStrictEqual([{ callId: 9 }]);
            expect(api.callsOf("hangUp")).toStrictEqual([]);
            expect(providerChannel().sentMessages).toStrictEqual([]);
            expect(api.callsOf("reportDeviceStatus").find(args => args.status === "SERVER_END_SEEN")?.detail).toBe("ENDED_BY_AGENT");
        });
        it("sends session.close for a limit", async () => {
            await startLiveCall();
            api.callStateAnswers.push(async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "LIMIT_REACHED" }));
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            expect(snapshot().endReason).toBe("LIMIT_REACHED");
            expect(providerChannel().sentMessages).toStrictEqual([SESSION_CLOSE_MESSAGE]);
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("shows a FAILED end as the dropped connection", async () => {
            await startLiveCall();
            api.callStateAnswers.push(async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "FAILED" }));
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            expect(snapshot().phase).toBe("failed");
            assertFailureTextKey(CONNECTION_DROPPED_TEXT_KEY);
            expect(snapshot().endReason).toBe(undefined);
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("looks once more 1.5 s after session.closed, then ends with PROVIDER_CLOSED, never with a hang-up", async () => {
            await startLiveCall();
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            expect(snapshot().phase).toBe("live");
            expect(api.callsOf("readCallState").length).toBe(1);
            await advance(1499);
            expect(snapshot().phase).toBe("live");
            expect(api.callsOf("readCallState").length).toBe(1);
            await advance(1);
            expect(api.callsOf("readCallState").length).toBe(2);
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().endReason).toBe("PROVIDER_CLOSED");
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("takes the reason the second look finds", async () => {
            await startLiveCall();
            api.callStateAnswers.push(async () => ({ callId: 9 }), async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "LIMIT_REACHED" }));
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            await advance(1500);
            expect(snapshot().endReason).toBe("LIMIT_REACHED");
        });
        it("ends a call whose open has not answered yet on session.closed, and hangs it up once open answers", async () => {
            const openAnswer = createDeferred();
            api.openSessionAnswers.push(() => openAnswer.promise);
            const starting = store.startAgentVoiceCall();
            await flushPromises();
            providerChannel().deliverProviderEvent({ type: "session.closed" });
            await flushPromises();
            expect(snapshot().phase).toBe("ended");
            expect(api.callsOf("readCallState")).toStrictEqual([]);
            openAnswer.resolve({ callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 });
            await starting;
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "USER_ENDED" }]);
        });
        it("changes nothing for another call id or a call that already ended", async () => {
            await startLiveCall();
            store.endAgentVoiceCallFromServer(8, "ENDED_BY_AGENT");
            expect(snapshot().phase).toBe("live");
            store.hangUpAgentVoiceCall();
            const callsBefore = api.calls.length;
            store.endAgentVoiceCallFromServer(9, "ENDED_BY_AGENT");
            expect(snapshot().endReason).toBe(undefined);
            expect(api.calls.length).toBe(callsBefore);
        });
        it("shares one read of the call state between triggers, and reads again after it finished", async () => {
            await startLiveCall();
            const callState = createDeferred();
            api.callStateAnswers.push(() => callState.promise);
            const first = store.checkAgentVoiceCallEndedOnServer();
            const second = store.checkAgentVoiceCallEndedOnServer();
            expect(first).toBe(second);
            callState.resolve({ callId: 9 });
            expect(await first).toBe(false);
            expect(api.callsOf("readCallState").length).toBe(1);
            await store.checkAgentVoiceCallEndedOnServer();
            expect(api.callsOf("readCallState").length).toBe(2);
        });
        it("answers false without a read for no call, and false with the call kept live when the read fails", async () => {
            expect(await store.checkAgentVoiceCallEndedOnServer()).toBe(false);
            expect(api.callsOf("readCallState")).toStrictEqual([]);
            await startLiveCall();
            api.callStateAnswers.push(async () => {
                throw new AgentVoiceApiError("call_not_found", 404);
            });
            expect(await store.checkAgentVoiceCallEndedOnServer()).toBe(false);
            expect(snapshot().phase).toBe("live");
        });
        it("reads the call state when the provider closes the channel, never after this browser closed it", async () => {
            await startLiveCall();
            providerChannel().closeFromProvider();
            await flushPromises();
            expect(api.callsOf("readCallState").length).toBe(1);
            store.hangUpAgentVoiceCall();
            providerChannel().closeFromProvider();
            await flushPromises();
            expect(api.callsOf("readCallState").length).toBe(1);
        });
        it("takes no hint from the late close of an earlier call’s channel while a newer call runs", async () => {
            await startLiveCall();
            const earlierChannel = providerChannel();
            store.hangUpAgentVoiceCall();
            await startLiveCall();
            earlierChannel.closeFromProvider();
            await flushPromises();
            expect(api.callsOf("readCallState")).toStrictEqual([]);
            expect(snapshot().phase).toBe("live");
        });
    });
    describe("the media path (ICE)", () => {
        it("reports MEDIA_CONNECTED once", async () => {
            await startLiveCall();
            peerConnection().changeIceConnectionState("connected");
            peerConnection().changeIceConnectionState("completed");
            expect(api.reportedStatuses().filter(status => status === "MEDIA_CONNECTED").length).toBe(1);
        });
        it("fails with the dropped connection on ICE failure when the server did not end the call", async () => {
            await startLiveCall();
            peerConnection().changeIceConnectionState("failed");
            await flushPromises();
            expect(snapshot().phase).toBe("failed");
            assertFailureTextKey(CONNECTION_DROPPED_TEXT_KEY);
            expect(api.callsOf("hangUp")).toStrictEqual([{ callId: 9, reason: "MEDIA_FAILED" }]);
            expect(api.callsOf("reportDeviceStatus").find(args => args.status === "MEDIA_FAILED")?.detail).toBe("ice failed");
        });
        it("ends with the server reason on ICE failure when the server ended the call", async () => {
            await startLiveCall();
            api.callStateAnswers.push(async () => ({ callId: 9, endedAt: "2026-11-25T10:00:00+00:00", endReason: "ENDED_BY_AGENT" }));
            peerConnection().changeIceConnectionState("failed");
            await flushPromises();
            expect(snapshot().phase).toBe("ended");
            expect(snapshot().endReason).toBe("ENDED_BY_AGENT");
            expect(api.callsOf("hangUp")).toStrictEqual([]);
        });
        it("asks the server at once on a disconnect and fails after five seconds without a reconnect", async () => {
            await startLiveCall();
            peerConnection().changeIceConnectionState("disconnected");
            await flushPromises();
            expect(api.callsOf("readCallState").length).toBe(1);
            await advance(4999);
            expect(snapshot().phase).toBe("live");
            await advance(1);
            expect(api.callsOf("readCallState").length).toBe(2);
            expect(snapshot().phase).toBe("failed");
            expect(api.callsOf("reportDeviceStatus").find(args => args.status === "MEDIA_FAILED")?.detail).toBe("ice disconnected for 5s");
        });
        it("stays live when the connection comes back within five seconds", async () => {
            await startLiveCall();
            peerConnection().changeIceConnectionState("disconnected");
            await advance(3000);
            peerConnection().changeIceConnectionState("connected");
            await advance(5000);
            expect(snapshot().phase).toBe("live");
            expect(api.callsOf("readCallState").length).toBe(1);
        });
        it("ignores the state of an earlier call’s connection", async () => {
            await startLiveCall();
            const earlierConnection = peerConnection();
            store.hangUpAgentVoiceCall();
            await startLiveCall();
            earlierConnection.changeIceConnectionState("failed");
            await flushPromises();
            expect(snapshot().phase).toBe("live");
            expect(api.callsOf("readCallState")).toStrictEqual([]);
        });
    });
    describe("the audio flow", () => {
        it("reports the first audio sent and played once each", async () => {
            await startLiveCall();
            peerConnection().statsReports = [
                { type: "outbound-rtp", kind: "audio", bytesSent: 100 },
                { type: "inbound-rtp", kind: "audio", bytesReceived: 50 },
            ];
            await advance(1000);
            await advance(1000);
            expect(api.reportedStatuses().filter(status => status === "FIRST_AUDIO_SENT").length).toBe(1);
            expect(api.reportedStatuses().filter(status => status === "FIRST_AUDIO_PLAYED").length).toBe(1);
        });
        it("reports MEDIA_FAILED when no audio left in the first ten seconds, and stays live", async () => {
            await startLiveCall();
            await advance(9000);
            expect(api.reportedStatuses().includes("MEDIA_FAILED")).toBe(false);
            await advance(1000);
            const mediaFailedReports = api.callsOf("reportDeviceStatus").filter(args => args.status === "MEDIA_FAILED");
            expect(mediaFailedReports.map(args => args.detail)).toStrictEqual(["no outbound audio bytes 10s after session.started"]);
            expect(snapshot().phase).toBe("live");
        });
        it("marks the microphone silent after six silent samples before the owner was heard, and reports it once", async () => {
            await startLiveCall();
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0.009 }];
            await advance(5000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
            await advance(1000);
            expect(snapshot().isMicrophoneSilent).toBe(true);
            expect(turnOfAgentVoiceCall(snapshot())).toBe("silentMicrophone");
            await advance(2000);
            expect(api.callsOf("reportDeviceStatus").filter(args => args.status === "INTERRUPTED").map(args => args.detail)).toStrictEqual(['microphone "fake microphone" silent, loudest level 0.009']);
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0.01 }];
            await advance(1000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
        });
        it("after the owner was heard (a level of 0.01 counts), counts only a full minute without voice as silence", async () => {
            await startLiveCall();
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0.01 }];
            await advance(1000);
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0 }];
            await advance(59000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
            await advance(1000);
            expect(snapshot().isMicrophoneSilent).toBe(true);
        });
        it("takes an input transcript as the owner heard", async () => {
            await startLiveCall();
            providerChannel().deliverProviderEvent({ type: "session.input_transcript.delta", delta: "Hello?" });
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0 }];
            await advance(10000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
        });
        it("never calls a muted microphone silent, and restarts the minute on unmute", async () => {
            await startLiveCall();
            providerChannel().deliverProviderEvent({ type: "session.input_transcript.delta", delta: "Wait." });
            peerConnection().statsReports = [{ type: "media-source", kind: "audio", audioLevel: 0 }];
            store.toggleAgentVoiceCallMute();
            await advance(90000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
            store.toggleAgentVoiceCallMute();
            await advance(59000);
            expect(snapshot().isMicrophoneSilent).toBe(false);
            await advance(1000);
            expect(snapshot().isMicrophoneSilent).toBe(true);
        });
    });
});
