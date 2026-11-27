import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { DEFAULT_AGENTS_TEXTS } from "../../provider/environment";
import { buildAgentVoiceCallerLabel, pickAgentVoiceCallerLabelTextKey } from "./agent-voice-caller-label";
const manifestKeys = new Set(JSON.parse(readFileSync(join(__dirname, "..", "..", "i18n-keys.json"), "utf8")).keys.map(entry => entry.key));
const ringWithLabelKey = (callerLabelKey) => ({
    callId: 8815,
    conversationId: 49167,
    answerToken: "1767259200.signature",
    ringExpiresAt: "2026-01-01T09:20:00.000Z",
    ringExpiresAtUnixMs: 1767259200000,
    callerLabelKey,
    callerContext: {},
});
/** A text function that marks what it translated, so a test sees which key reached it. */
const markingTranslate = (key, params) => `[translated] ${DEFAULT_AGENTS_TEXTS.translate(key, { agentName: "Sam", ...params })}`;
describe("pickAgentVoiceCallerLabelTextKey", () => {
    it("resolves the backend's key and its alias to the same key of the manifest", () => {
        const wantsToTalk = pickAgentVoiceCallerLabelTextKey("%agentName% wants to talk");
        const isCallingYou = pickAgentVoiceCallerLabelTextKey("%agentName% is calling you");
        expect(wantsToTalk).toStrictEqual({ labelTextKey: "%agentName% wants to talk", isTranslatable: true });
        expect(isCallingYou).toStrictEqual(wantsToTalk);
        expect(manifestKeys.has(wantsToTalk.labelTextKey)).toBe(true);
    });
    it("gives a ring without a label key the bundle line", () => {
        expect(pickAgentVoiceCallerLabelTextKey("")).toStrictEqual({ labelTextKey: "%agentName% wants to talk", isTranslatable: true });
    });
    it("keeps an unknown key as it came and marks it untranslatable, also a name of the object prototype", () => {
        expect(pickAgentVoiceCallerLabelTextKey("%agentName% has news")).toStrictEqual({ labelTextKey: "%agentName% has news", isTranslatable: false });
        expect(pickAgentVoiceCallerLabelTextKey("toString")).toStrictEqual({ labelTextKey: "toString", isTranslatable: false });
    });
});
describe("buildAgentVoiceCallerLabel", () => {
    it("sends a known key through the text function", () => {
        expect(buildAgentVoiceCallerLabel(ringWithLabelKey("%agentName% is calling you"), markingTranslate, "Sam")).toStrictEqual({
            text: "[translated] Sam wants to talk",
        });
    });
    it("shows an unknown key untranslated with the agent name filled in", () => {
        expect(buildAgentVoiceCallerLabel(ringWithLabelKey("%agentName% has news for %ownerName%"), markingTranslate, "Sam")).toStrictEqual({
            text: "Sam has news for %ownerName%",
        });
    });
});
