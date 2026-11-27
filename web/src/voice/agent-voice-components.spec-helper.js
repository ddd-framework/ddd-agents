// Spec support for the specs that render the voice components with React and jsdom. No source imports this file, and
// no entry reaches it.
//
// Unlike `call/agent-voice-call-store.spec-helper.ts` (whose `installFakeBrowser` replaces `window` for the store's
// plain functions), this keeps jsdom's `window`, so React renders; only WebRTC, the microphone and the audio element
// are fakes, the same classes the store specs use.
import { act } from "@testing-library/react";
import { vi } from "vitest";
import { bindAgentVoiceApi, dismissAgentVoiceCallOutcome, hangUpAgentVoiceCall, startAgentVoiceCall, } from "./call/agent-voice-call-store";
import { FakeAudioElement, FakeMicrophoneStream, FakePeerConnection, flushPromises, RecordingAgentVoiceApi, } from "./call/agent-voice-call-store.spec-helper";
/** Puts `RTCPeerConnection`, `Audio` and `navigator.mediaDevices` in place as fakes; `vi.unstubAllGlobals()` undoes two. */
export const installFakeMedia = () => {
    const media = {
        microphoneAnswer: "granted",
        steps: [],
        lastPeerConnection: () => {
            const connection = FakePeerConnection.instances[FakePeerConnection.instances.length - 1];
            if (connection === undefined)
                throw new Error("no peer connection was created");
            return connection;
        },
    };
    vi.stubGlobal("RTCPeerConnection", FakePeerConnection);
    vi.stubGlobal("Audio", FakeAudioElement);
    Object.defineProperty(globalThis.navigator, "mediaDevices", {
        configurable: true,
        value: {
            getUserMedia: async () => {
                media.steps.push("getUserMedia");
                if (media.microphoneAnswer === "denied")
                    throw new Error("NotAllowedError");
                return new FakeMicrophoneStream();
            },
        },
    });
    return media;
};
/** A recording adapter that also writes each operation into the steps of the fake media, so a spec sees the order. */
export class StepRecordingAgentVoiceApi extends RecordingAgentVoiceApi {
    media;
    constructor(media) {
        super();
        this.media = media;
    }
    async openSession(args) {
        this.media.steps.push("openSession");
        return super.openSession(args);
    }
}
/** Lets the store's start run to its end inside `act`, so every mounted component shows the result. */
export const settleVoiceCall = async () => {
    await act(async () => {
        await flushPromises();
    });
};
/** Binds `api`, starts a voice chat and lets the provider report `session.started`: the call is live. */
export const startLiveAgentVoiceCall = async (api, media, params = {}) => {
    bindAgentVoiceApi(api);
    await act(async () => {
        await startAgentVoiceCall(params);
    });
    act(() => {
        media.lastPeerConnection().dataChannel?.deliverProviderEvent({ type: "session.started" });
    });
};
/** The provider sends a piece of what a speaker says in the live call. */
export const deliverAgentVoiceTranscript = (media, speaker, delta) => {
    act(() => {
        media.lastPeerConnection().dataChannel?.deliverProviderEvent({
            type: speaker === "agent" ? "session.output_transcript.delta" : "session.input_transcript.delta",
            delta,
        });
    });
};
/** Back to no call (the store is module level and outlives a spec). */
export const resetAgentVoiceCall = () => {
    act(() => {
        hangUpAgentVoiceCall();
        dismissAgentVoiceCallOutcome();
    });
};
