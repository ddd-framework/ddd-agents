import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { agentVoiceCallOutcomeTitleTextKey, endedAgentVoiceCallTitleTextKey } from "./agent-voice-call-outcome-title-text-key";
const manifestKeys = new Set(JSON.parse(readFileSync(join(__dirname, "..", "..", "i18n-keys.json"), "utf8")).keys.map(entry => entry.key));
/** The title, checked to be a key the application can translate. */
const outcomeTitle = (call) => {
    const titleTextKey = agentVoiceCallOutcomeTitleTextKey(call);
    expect(manifestKeys.has(titleTextKey), `not in i18n-keys.json: ${titleTextKey}`).toBe(true);
    return titleTextKey;
};
const CONNECTED_AT_MS = 1767259200000;
describe("endedAgentVoiceCallTitleTextKey", () => {
    it("names the agent ending the call, a limit, and every other end as an ended voice chat", () => {
        expect(endedAgentVoiceCallTitleTextKey({ endReason: "ENDED_BY_AGENT" })).toBe("%agentName% ended the voice chat");
        expect(endedAgentVoiceCallTitleTextKey({ endReason: "LIMIT_REACHED" })).toBe("Voice chat limit reached");
        for (const endReason of [undefined, "PROVIDER_CLOSED", "COMPLETED", "reaped_stale", ""]) {
            expect(endedAgentVoiceCallTitleTextKey({ endReason })).toBe("Voice chat ended");
        }
    });
});
describe("agentVoiceCallOutcomeTitleTextKey", () => {
    it("says failed for a failed call, connected or not, whatever the end reason", () => {
        expect(outcomeTitle({ phase: "failed" })).toBe("Voice chat failed");
        expect(outcomeTitle({ phase: "failed", connectedAtMs: CONNECTED_AT_MS, endReason: "ENDED_BY_AGENT" })).toBe("Voice chat failed");
    });
    it("words a connected ended call by its end reason", () => {
        expect(outcomeTitle({ phase: "ended", connectedAtMs: CONNECTED_AT_MS, endReason: "ENDED_BY_AGENT" })).toBe("%agentName% ended the voice chat");
        expect(outcomeTitle({ phase: "ended", connectedAtMs: CONNECTED_AT_MS, endReason: "LIMIT_REACHED" })).toBe("Voice chat limit reached");
        expect(outcomeTitle({ phase: "ended", connectedAtMs: CONNECTED_AT_MS, endReason: "PROVIDER_CLOSED" })).toBe("Voice chat ended");
        expect(outcomeTitle({ phase: "ended", connectedAtMs: CONNECTED_AT_MS })).toBe("Voice chat ended");
        expect(outcomeTitle({ phase: "ended", connectedAtMs: CONNECTED_AT_MS, endReason: "reaped_stale" })).toBe("Voice chat ended");
    });
    it("calls an ended call that never connected cancelled, also when the server ended it", () => {
        expect(outcomeTitle({ phase: "ended" })).toBe("Voice chat cancelled");
        expect(outcomeTitle({ phase: "ended", endReason: "ENDED_BY_AGENT" })).toBe("Voice chat cancelled");
    });
});
