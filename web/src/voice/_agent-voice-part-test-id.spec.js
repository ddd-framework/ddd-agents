import { describe, expect, it } from "vitest";
import { agentVoicePartTestId } from "./agent-voice-part-test-id";
describe("the test id of an inner element of a voice component", () => {
    it("is the element's fixed id while the component has no testId", () => {
        expect(agentVoicePartTestId(undefined, "mute", "agent-voice-call-mute")).toBe("agent-voice-call-mute");
    });
    it("is ${testId}-<part> once the component has one", () => {
        expect(agentVoicePartTestId("side-band", "mute", "agent-voice-call-mute")).toBe("side-band-mute");
    });
});
