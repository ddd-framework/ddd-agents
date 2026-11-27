// Spec support for the modules that import React at runtime (the call store, the incoming ring store and what imports
// them). No source imports this file, and no entry reaches it.
//
// The store modules use React only inside their hooks (`useSyncExternalStore`, `useEffect`); their module bodies never
// call it. A spec that loads them replaces `react` with the stand-in below, so their plain functions run outside a
// render:
//
//   vi.mock("react", async () => (await import("./agent-voice-call-store.spec-helper")).createFakeReactModule());
//
// The stand-in's `useSyncExternalStore` hands back the store's own `subscribe`, which is how a spec counts the store's
// notifications. No hook of the package is tested through the stand-in.
//
// WebRTC, the microphone, the audio element and `window` (timers, `pagehide`) are staged as plain fakes
// (`installFakeBrowser`). `window`'s timer functions call the global ones, which the specs replace with vitest's fake
// timers.
import { vi } from "vitest";
/** Every `useSyncExternalStore` call of the stand-in, in order. */
const storeHandles = [];
/** The stand-in for `react` (see the header): the hooks the store modules call, nothing that renders. */
export const createFakeReactModule = () => {
    const useSyncExternalStore = (subscribe, getSnapshot) => {
        storeHandles.push({ subscribe, getSnapshot });
        return getSnapshot();
    };
    const useEffect = () => undefined;
    const useState = (initial) => [typeof initial === "function" ? initial() : initial, () => undefined];
    const useMemo = (build) => build();
    const useCallback = (callback) => callback;
    const useRef = (current) => ({ current });
    const useId = () => "test-id";
    const createContext = (defaultValue) => ({ defaultValue });
    const useContext = (context) => context.defaultValue;
    const hooks = { useSyncExternalStore, useEffect, useState, useMemo, useCallback, useRef, useId, createContext, useContext };
    return { ...hooks, default: hooks };
};
/** Calls a store hook once through the stand-in and returns the store's `subscribe` and `getSnapshot`. */
export const readStoreHandle = (callHook) => {
    const countBefore = storeHandles.length;
    callHook();
    if (storeHandles.length !== countBefore + 1)
        throw new Error("the hook did not call useSyncExternalStore once");
    return storeHandles[storeHandles.length - 1];
};
/** Counts how often the store tells its subscribers; `stop` unsubscribes. */
export const countNotifications = (handle) => {
    const counter = { count: 0, stop: () => undefined };
    counter.stop = handle.subscribe(() => {
        counter.count += 1;
    });
    return counter;
};
// ---------------------------------------------------------------------------------------------------------------- browser
export class FakeAudioTrack {
    enabled = true;
    stopped = false;
    label = "fake microphone";
    muted = false;
    readyState = "live";
    stop() {
        this.stopped = true;
    }
}
export class FakeMicrophoneStream {
    tracks = [new FakeAudioTrack()];
    getTracks() {
        return this.tracks;
    }
    getAudioTracks() {
        return this.tracks;
    }
}
export class FakeDataChannel {
    label;
    readyState = "open";
    sentMessages = [];
    closed = false;
    onmessage = null;
    onclose = null;
    constructor(label) {
        this.label = label;
    }
    send(message) {
        this.sentMessages.push(message);
    }
    close() {
        this.closed = true;
        this.readyState = "closed";
    }
    /** The provider sends one event over the channel. */
    deliverProviderEvent(providerEvent) {
        this.onmessage?.({ data: typeof providerEvent === "string" ? providerEvent : JSON.stringify(providerEvent) });
    }
    /** The provider closes the channel (not this browser). */
    closeFromProvider() {
        this.readyState = "closed";
        this.onclose?.();
    }
}
export class FakePeerConnection {
    static instances = [];
    iceGatheringState = "complete";
    iceConnectionState = "new";
    localDescription = null;
    remoteDescription = null;
    addedTracks = [];
    dataChannel;
    closed = false;
    statsReports = [];
    ontrack = null;
    oniceconnectionstatechange = null;
    constructor() {
        FakePeerConnection.instances.push(this);
    }
    addTrack(track) {
        this.addedTracks.push(track);
    }
    createDataChannel(label) {
        this.dataChannel = new FakeDataChannel(label);
        return this.dataChannel;
    }
    async createOffer() {
        return { type: "offer", sdp: "v=0 fake offer" };
    }
    async setLocalDescription(description) {
        this.localDescription = description;
    }
    async setRemoteDescription(description) {
        this.remoteDescription = description;
    }
    addEventListener() {
        return undefined;
    }
    close() {
        this.closed = true;
    }
    async getStats() {
        return new Map(this.statsReports.map((report, index) => [String(index), report]));
    }
    changeIceConnectionState(iceConnectionState) {
        this.iceConnectionState = iceConnectionState;
        this.oniceconnectionstatechange?.();
    }
}
export class FakeAudioElement {
    static instances = [];
    autoplay = false;
    srcObject = null;
    paused = false;
    constructor() {
        FakeAudioElement.instances.push(this);
    }
    pause() {
        this.paused = true;
    }
}
/** Puts `window`, `navigator.mediaDevices`, `RTCPeerConnection` and `Audio` in place as fakes, for the spec file. */
export const installFakeBrowser = () => {
    const browser = {
        microphoneAnswer: "granted",
        microphoneRequests: [],
        microphoneStreams: [],
        pageHideListeners: [],
        lastPeerConnection: () => {
            const connection = FakePeerConnection.instances[FakePeerConnection.instances.length - 1];
            if (connection === undefined)
                throw new Error("no peer connection was created");
            return connection;
        },
    };
    const fakeWindow = {
        setTimeout: (callback, delayMs) => globalThis.setTimeout(callback, delayMs),
        clearTimeout: (timer) => globalThis.clearTimeout(timer),
        setInterval: (callback, delayMs) => globalThis.setInterval(callback, delayMs),
        clearInterval: (timer) => globalThis.clearInterval(timer),
        addEventListener: (type, listener) => {
            if (type === "pagehide")
                browser.pageHideListeners.push(listener);
        },
    };
    vi.stubGlobal("window", fakeWindow);
    vi.stubGlobal("RTCPeerConnection", FakePeerConnection);
    vi.stubGlobal("Audio", FakeAudioElement);
    Object.defineProperty(globalThis.navigator, "mediaDevices", {
        configurable: true,
        value: {
            getUserMedia: async (constraints) => {
                browser.microphoneRequests.push(constraints);
                if (browser.microphoneAnswer === "denied")
                    throw new Error("NotAllowedError");
                const stream = new FakeMicrophoneStream();
                browser.microphoneStreams.push(stream);
                return stream;
            },
        },
    });
    return browser;
};
/** Lets every pending promise reaction and I/O callback run (the specs' fake timers leave `setImmediate` real). */
export const flushPromises = async (rounds = 10) => {
    for (let round = 0; round < rounds; round += 1) {
        await new Promise(resolve => setImmediate(resolve));
    }
};
export const createDeferred = () => {
    const deferred = {};
    deferred.promise = new Promise((resolve, reject) => {
        deferred.resolve = resolve;
        deferred.reject = reject;
    });
    return deferred;
};
/**
 * An adapter that records every call in order and answers from the queues below (a value, or a function that may
 * throw or return a pending promise). An empty queue answers the default.
 */
export class RecordingAgentVoiceApi {
    calls = [];
    openSessionAnswers = [];
    callStateAnswers = [];
    ringAnswers = [];
    defaultOpenSessionAnswer = { callId: 9, sdpAnswer: "v=0 fake answer", conversationId: 41 };
    callsOf(operation) {
        return this.calls.filter(call => call.operation === operation).map(call => call.args);
    }
    /** The `status` of every device status report, in order. */
    reportedStatuses() {
        return this.callsOf("reportDeviceStatus").map(args => args.status);
    }
    async openSession(args) {
        this.calls.push({ operation: "openSession", args });
        const answer = this.openSessionAnswers.shift();
        return answer ? answer() : this.defaultOpenSessionAnswer;
    }
    async hangUp(args) {
        this.calls.push({ operation: "hangUp", args });
    }
    async reportDeviceStatus(args) {
        this.calls.push({ operation: "reportDeviceStatus", args });
    }
    async readCallState(args) {
        this.calls.push({ operation: "readCallState", args });
        const answer = this.callStateAnswers.shift();
        return answer ? answer() : { callId: args.callId };
    }
    async answerRing(args) {
        this.calls.push({ operation: "answerRing", args });
        const answer = this.ringAnswers.shift();
        return answer ? answer() : { won: true, conversationId: 41 };
    }
    async declineRing(args) {
        this.calls.push({ operation: "declineRing", args });
    }
    forget() {
        this.calls.length = 0;
    }
}
