import { describe, expect, it } from "vitest";
import { buildUnsavedAgentVoiceCallLines } from "./build-unsaved-agent-voice-call-lines";
describe("buildUnsavedAgentVoiceCallLines", () => {
    it("streams every line while nothing is saved yet", () => {
        const lines = [
            { id: 1, speaker: "agent", text: "Hello, good to hear you. What can I do for you?" },
            { id: 2, speaker: "owner", text: "I want to change my opening hours" },
        ];
        expect(buildUnsavedAgentVoiceCallLines(lines, [])).toStrictEqual(lines);
    });
    it("takes a saved sentence out and keeps the rest of the line", () => {
        const lines = [{ id: 1, speaker: "agent", text: "Hello, good to hear you. What can I do for you?" }];
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "agent", text: "Hello, good to hear you." }])).toStrictEqual([
            { id: 1, speaker: "agent", text: "What can I do for you?" },
        ]);
    });
    it("drops a fully saved line even when the backend cut it elsewhere and spacing and marks differ", () => {
        const lines = [{ id: 1, speaker: "agent", text: "Sure — I moved it to 9 am. Anything else?" }];
        const savedMessages = [
            { speaker: "agent", text: "Sure, I moved it" },
            { speaker: "agent", text: "to 9 am.  Anything else?" },
        ];
        expect(buildUnsavedAgentVoiceCallLines(lines, savedMessages)).toStrictEqual([]);
    });
    it("matches case-blind and over letters of every script", () => {
        const lines = [{ id: 1, speaker: "owner", text: "Grüße an Zoë! Danke." }];
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "owner", text: "GRÜSSE an zoë" }])).toStrictEqual(lines);
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "owner", text: "grüße an ZOË" }])).toStrictEqual([
            { id: 1, speaker: "owner", text: "Danke." },
        ]);
    });
    it("matches the speakers apart: the saved words of one never take out the other", () => {
        const lines = [
            { id: 1, speaker: "owner", text: "Okay." },
            { id: 2, speaker: "agent", text: "Okay." },
        ];
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "owner", text: "Okay." }])).toStrictEqual([{ id: 2, speaker: "agent", text: "Okay." }]);
    });
    it("needs two saved sentences for a sentence said twice", () => {
        const lines = [
            { id: 1, speaker: "owner", text: "Yes." },
            { id: 3, speaker: "owner", text: "Yes." },
        ];
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "owner", text: "Yes." }])).toStrictEqual([{ id: 3, speaker: "owner", text: "Yes." }]);
        expect(buildUnsavedAgentVoiceCallLines(lines, [
            { speaker: "owner", text: "Yes." },
            { speaker: "owner", text: "Yes." },
        ])).toStrictEqual([]);
    });
    it("looks for a sentence only after the last one found, never before it", () => {
        const lines = [{ id: 1, speaker: "agent", text: "Second. First." }];
        // "first" lies before "second" in the saved stream: once "second" matched, "first" is searched after it.
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "agent", text: "First. Second." }])).toStrictEqual([
            { id: 1, speaker: "agent", text: "First." },
        ]);
    });
    it("streams the sentence still being spoken, which has no end mark yet", () => {
        const lines = [{ id: 1, speaker: "agent", text: "First, the good news. Your reach grew more than" }];
        expect(buildUnsavedAgentVoiceCallLines(lines, [{ speaker: "agent", text: "First, the good news." }])).toStrictEqual([
            { id: 1, speaker: "agent", text: "Your reach grew more than" },
        ]);
    });
    it("cuts sentences at an ellipsis, a question mark and an exclamation mark", () => {
        const lines = [{ id: 1, speaker: "agent", text: "Well… Really? Great! Done" }];
        const savedMessages = [{ speaker: "agent", text: "Well… Great!" }];
        expect(buildUnsavedAgentVoiceCallLines(lines, savedMessages)).toStrictEqual([{ id: 1, speaker: "agent", text: "Really? Done" }]);
    });
    it("drops a line of marks only (nothing to match, nothing to show)", () => {
        const lines = [{ id: 1, speaker: "owner", text: "… ?" }];
        expect(buildUnsavedAgentVoiceCallLines(lines, [])).toStrictEqual([]);
    });
});
