import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { agentVoiceSampleKeyOf, readAgentVoiceSamplePlayerState, resetAgentVoiceSamplePlayer, stopAgentVoiceSample, subscribeAgentVoiceSamplePlayer, toggleAgentVoiceSample, } from "./agent-voice-sample-player";
// The player of the voice samples: one audio element for the page (a new sample stops the one playing, the playing one
// stops on a second press), each sample fetched once, a 404 told apart from other failures, a sample asked for while
// another loads wins, and a refused play() reported. jsdom does not play media: play() and pause() are stand-ins that
// fire the events a browser fires.
let playedSources = [];
let playRefused = false;
beforeEach(() => {
    playedSources = [];
    playRefused = false;
    let objectUrlCount = 0;
    vi.spyOn(URL, "createObjectURL").mockImplementation(() => `blob:sample-${++objectUrlCount}`);
    vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => { });
    vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(function () {
        if (playRefused)
            return Promise.reject(new DOMException("blocked", "NotAllowedError"));
        playedSources.push(this.src);
        return Promise.resolve();
    });
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(function () {
        this.dispatchEvent(new Event("pause"));
    });
});
afterEach(() => {
    resetAgentVoiceSamplePlayer();
    vi.restoreAllMocks();
});
const waveBlob = () => new Blob(["RIFF"], { type: "audio/wav" });
describe("toggleAgentVoiceSample", () => {
    it("plays a sample, fetches it once and stops it on a second press", async () => {
        const fetchSample = vi.fn(async () => waveBlob());
        expect(await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample })).toBeNull();
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBe("marin/de");
        expect(playedSources).toEqual(["blob:sample-1"]);
        expect(await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample })).toBeNull();
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBeNull();
        await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample });
        expect(fetchSample).toHaveBeenCalledTimes(1);
        expect(playedSources).toEqual(["blob:sample-1", "blob:sample-1"]);
    });
    it("stops the playing sample when another starts: one audio element for the page", async () => {
        const fetchSample = vi.fn(async () => waveBlob());
        await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample });
        await toggleAgentVoiceSample({ voiceId: "cedar", languageCode: "de", fetchSample });
        expect(HTMLMediaElement.prototype.pause).toHaveBeenCalled();
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBe("cedar/de");
        expect(playedSources).toEqual(["blob:sample-1", "blob:sample-2"]);
    });
    it("tells a missing sample (404) apart from a failed fetch, and plays nothing", async () => {
        expect(await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "xx", fetchSample: () => Promise.reject({ status: 404 }) })).toBe("notFound");
        expect(await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample: () => Promise.reject(new Error("offline")) })).toBe("loadFailed");
        expect(readAgentVoiceSamplePlayerState()).toEqual({ playingSampleKey: null, loadingSampleKey: null });
        expect(playedSources).toEqual([]);
    });
    it("lets a sample asked for while another loads win", async () => {
        let resolveSlowFetch = () => { };
        const slowFetch = () => new Promise(resolve => (resolveSlowFetch = resolve));
        const slowToggle = toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample: slowFetch });
        expect(readAgentVoiceSamplePlayerState().loadingSampleKey).toBe("marin/de");
        await toggleAgentVoiceSample({ voiceId: "cedar", languageCode: "de", fetchSample: async () => waveBlob() });
        resolveSlowFetch(waveBlob());
        expect(await slowToggle).toBeNull();
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBe("cedar/de");
        expect(playedSources).toHaveLength(1);
    });
    it("reports a play() the browser refuses and leaves nothing marked as playing", async () => {
        playRefused = true;
        expect(await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample: async () => waveBlob() })).toBe("playFailed");
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBeNull();
    });
    it("tells its subscribers about every change", async () => {
        const listener = vi.fn();
        const unsubscribe = subscribeAgentVoiceSamplePlayer(listener);
        await toggleAgentVoiceSample({ voiceId: "marin", languageCode: "de", fetchSample: async () => waveBlob() });
        stopAgentVoiceSample();
        unsubscribe();
        expect(listener.mock.calls.length).toBeGreaterThanOrEqual(3);
    });
});
describe("agentVoiceSampleKeyOf", () => {
    it("joins voice and language", () => {
        expect(agentVoiceSampleKeyOf("marin", "de")).toBe("marin/de");
    });
});
