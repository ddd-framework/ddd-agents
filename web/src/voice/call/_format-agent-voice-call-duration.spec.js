import { describe, expect, it } from "vitest";
import { formatAgentVoiceCallDuration } from "./format-agent-voice-call-duration";
describe("formatAgentVoiceCallDuration", () => {
    it("writes minutes and two-digit seconds", () => {
        expect(formatAgentVoiceCallDuration(0)).toBe("0:00");
        expect(formatAgentVoiceCallDuration(7)).toBe("0:07");
        expect(formatAgentVoiceCallDuration(59)).toBe("0:59");
        expect(formatAgentVoiceCallDuration(60)).toBe("1:00");
        expect(formatAgentVoiceCallDuration(605)).toBe("10:05");
    });
    it("keeps counting minutes past an hour (no hour field)", () => {
        expect(formatAgentVoiceCallDuration(3599)).toBe("59:59");
        expect(formatAgentVoiceCallDuration(3600)).toBe("60:00");
    });
});
