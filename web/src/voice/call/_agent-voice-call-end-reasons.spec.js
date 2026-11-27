import { describe, expect, it, vi } from "vitest";
import { installFakeBrowser } from "./agent-voice-call-store.spec-helper";
import { AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT, AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED } from "./agent-voice-call-end-reasons";
// The store modules import React at runtime; the stand-in of the spec helper replaces it.
vi.mock("react", async () => (await import("./agent-voice-call-store.spec-helper")).createFakeReactModule());
describe("the end reasons the band words differently", () => {
    it("are the call's end reasons of the backend", () => {
        expect(AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT).toBe("ENDED_BY_AGENT");
        expect(AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED).toBe("LIMIT_REACHED");
    });
    it("are still exported by the call store, with the same values", async () => {
        // The fake browser is in place before the store loads.
        installFakeBrowser();
        const store = await import("./agent-voice-call-store");
        expect(store.AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT).toBe(AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT);
        expect(store.AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED).toBe(AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED);
    });
});
