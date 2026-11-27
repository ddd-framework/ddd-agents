import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { RecordingAgentVoiceApi } from "../call/agent-voice-call-store.spec-helper";
import { installFakeMedia, resetAgentVoiceCall, startLiveAgentVoiceCall } from "../agent-voice-components.spec-helper";
import { AgentVoiceInputGlow } from "./AgentVoiceInputGlow";
/** The glow's `testId`: on its box (the canvases carry none); `agent-voice-input-glow` by default. */
describe("AgentVoiceInputGlow test id", () => {
    afterEach(() => {
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    test("keeps the fixed id without a testId", async () => {
        render(<AgentVoiceInputGlow />);
        await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), installFakeMedia());
        const glow = screen.getByTestId("agent-voice-input-glow");
        expect(glow.querySelectorAll("canvas")).toHaveLength(2);
    });
    test("puts the testId on the glow's box", async () => {
        render(<AgentVoiceInputGlow testId="window-input-glow"/>);
        await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), installFakeMedia());
        expect(screen.getByTestId("window-input-glow").querySelectorAll("canvas")).toHaveLength(2);
        expect(screen.queryByTestId("agent-voice-input-glow")).toBeNull();
    });
    test("renders nothing while no voice chat runs", () => {
        const { container } = render(<AgentVoiceInputGlow testId="window-input-glow"/>);
        expect(container).toBeEmptyDOMElement();
    });
});
