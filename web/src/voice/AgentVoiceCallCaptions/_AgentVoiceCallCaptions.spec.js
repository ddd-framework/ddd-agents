import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { RecordingAgentVoiceApi } from "../call/agent-voice-call-store.spec-helper";
import { deliverAgentVoiceTranscript, installFakeMedia, resetAgentVoiceCall, startLiveAgentVoiceCall, } from "../agent-voice-components.spec-helper";
import { AgentVoiceCallCaptions } from "./AgentVoiceCallCaptions";
/** The captions' `testId`: on the captions and, as `${testId}-caption-<speaker>`, on each line; the fixed ids by default. */
const captionTestIds = () => Array.from(document.querySelectorAll("[data-testid]"))
    .map((element) => element.getAttribute("data-testid") ?? "")
    .filter((testId) => testId.includes("caption"));
/** A live call outside a chat (every line shows), the agent and the owner each said one sentence. */
const startCallWithTwoLines = async () => {
    const media = installFakeMedia();
    await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), media);
    deliverAgentVoiceTranscript(media, "agent", "Hello, how can I help?");
    deliverAgentVoiceTranscript(media, "owner", "Tell me about today.");
};
describe("AgentVoiceCallCaptions test ids", () => {
    afterEach(() => {
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    test("keeps the fixed ids without a testId", async () => {
        render(<AgentVoiceCallCaptions />);
        await startCallWithTwoLines();
        expect(captionTestIds()).toStrictEqual([
            "agent-voice-call-captions",
            "agent-voice-call-caption-agent",
            "agent-voice-call-caption-owner",
        ]);
        expect(screen.getByTestId("agent-voice-call-caption-owner")).toHaveTextContent("Tell me about today.");
    });
    test("puts the testId on the captions and ${testId}-caption-<speaker> on each line", async () => {
        render(<AgentVoiceCallCaptions testId="window-captions"/>);
        await startCallWithTwoLines();
        expect(captionTestIds()).toStrictEqual(["window-captions", "window-captions-caption-agent", "window-captions-caption-owner"]);
        expect(screen.getByTestId("window-captions-caption-agent")).toHaveTextContent("Hello, how can I help?");
        expect(screen.getByTestId("window-captions")).toContainElement(screen.getByTestId("window-captions-caption-owner"));
    });
    test("renders nothing while no voice chat runs, testId or not", () => {
        const { container } = render(<AgentVoiceCallCaptions testId="window-captions"/>);
        expect(container).toBeEmptyDOMElement();
    });
});
