import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { AgentsProvider } from "../../provider/AgentsProvider";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { AgentVoiceApiError } from "../agent-voice-api";
import { bindAgentVoiceApi, readAgentVoiceCallSnapshot } from "../call/agent-voice-call-store";
import { installFakeMedia, resetAgentVoiceCall, settleVoiceCall, StepRecordingAgentVoiceApi, } from "../agent-voice-components.spec-helper";
import { AgentVoiceCallBand } from "../AgentVoiceCallBand/AgentVoiceCallBand";
import { AgentVoiceChatStartButton } from "./AgentVoiceChatStartButton";
/**
 * The start button's additions: `onStart` runs in the click before the voice chat starts, and the label shows through
 * the environment's tooltip when the application replaced the package's. Beside them, what a start that fails before
 * `open` answers leaves: the store's failed call, which every mounted band shows.
 */
/** An application's tooltip: renders its child and the text key it was handed, so a spec sees that it was used. */
const ApplicationTooltip = (props) => (<span data-testid="application-tooltip" data-text={props.text} data-agent-name={String(props.replacements?.agentName)}>
    {props.children}
  </span>);
const bindStepRecordingApi = (media) => {
    const api = new StepRecordingAgentVoiceApi(media);
    bindAgentVoiceApi(api);
    return api;
};
describe("AgentVoiceChatStartButton onStart", () => {
    afterEach(async () => {
        await settleVoiceCall();
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    test("runs synchronously in the click, before the microphone prompt and before open", async () => {
        const media = installFakeMedia();
        const api = bindStepRecordingApi(media);
        const onStart = vi.fn(() => {
            media.steps.push("onStart");
        });
        render(<AgentVoiceChatStartButton onStart={onStart}/>);
        fireEvent.click(screen.getByTestId("agent-voice-chat-start"));
        // Still inside the click's task: onStart ran once, then the store asked for the microphone (in the same gesture);
        // open has not been sent yet.
        expect(onStart).toHaveBeenCalledTimes(1);
        expect(media.steps).toStrictEqual(["onStart", "getUserMedia"]);
        await settleVoiceCall();
        expect(media.steps).toStrictEqual(["onStart", "getUserMedia", "openSession"]);
        expect(api.callsOf("openSession")).toHaveLength(1);
    });
    test("hands the start its agent space and conversation as before", async () => {
        const media = installFakeMedia();
        const api = bindStepRecordingApi(media);
        render(<AgentVoiceChatStartButton agentSpaceId={3} conversationId={41} onStart={() => undefined}/>);
        fireEvent.click(screen.getByTestId("agent-voice-chat-start"));
        await settleVoiceCall();
        expect(api.callsOf("openSession")[0]).toMatchObject({ agentSpaceId: 3, conversationId: 41 });
    });
    test("without onStart the click starts the voice chat as before", async () => {
        const media = installFakeMedia();
        const api = bindStepRecordingApi(media);
        render(<AgentVoiceChatStartButton />);
        fireEvent.click(screen.getByTestId("agent-voice-chat-start"));
        await settleVoiceCall();
        expect(media.steps).toStrictEqual(["getUserMedia", "openSession"]);
        expect(api.callsOf("openSession")).toHaveLength(1);
        expect(readAgentVoiceCallSnapshot().phase).toBe("connecting");
    });
});
describe("AgentVoiceChatStartButton tooltip", () => {
    const appEnvironment = getAgentsEnvironment();
    afterEach(() => {
        cleanup();
        setAgentsEnvironment(appEnvironment);
    });
    test("without an application tooltip the label is the button's title, as before", () => {
        render(<AgentVoiceChatStartButton />);
        const button = screen.getByTestId("agent-voice-chat-start");
        expect(button).toHaveAttribute("title", "Talk to the assistant");
        expect(button).toHaveAccessibleName("Talk to the assistant");
        expect(screen.queryByRole("tooltip")).toBeNull();
        expect(button.parentElement).toBe(document.body.firstElementChild);
    });
    test("renders the label through the tooltip the application put into the app-wide environment", () => {
        setAgentsEnvironment({ ...appEnvironment, agentDisplayName: "Ada", components: { Tooltip: ApplicationTooltip } });
        render(<AgentVoiceChatStartButton />);
        const tooltip = screen.getByTestId("application-tooltip");
        const button = screen.getByTestId("agent-voice-chat-start");
        expect(tooltip).toContainElement(button);
        expect(tooltip).toHaveAttribute("data-text", "Talk to %agentName%");
        expect(tooltip).toHaveAttribute("data-agent-name", "Ada");
        expect(button).not.toHaveAttribute("title");
        expect(button).toHaveAccessibleName("Talk to Ada");
    });
    test("renders the label through a subtree's tooltip too", () => {
        render(<AgentsProvider environment={{ components: { Tooltip: ApplicationTooltip } }}>
        <AgentVoiceChatStartButton testId="own-start"/>
      </AgentsProvider>);
        expect(screen.getByTestId("application-tooltip")).toContainElement(screen.getByTestId("own-start"));
        expect(screen.getByTestId("own-start")).not.toHaveAttribute("title");
    });
});
describe("a start that fails before open answers", () => {
    afterEach(async () => {
        await settleVoiceCall();
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    /** Two bands as an application mounts them: one in a chat, one outside every chat (a page header). */
    const renderTwoBandsAndTheButton = () => render(<>
        <section aria-label="chat">
          <AgentVoiceCallBand testId="chat-band" agentSpaceId={3}/>
          <AgentVoiceChatStartButton agentSpaceId={3}/>
        </section>
        <header>
          <AgentVoiceCallBand testId="page-band"/>
        </header>
      </>);
    test("a refused microphone is the failed call every mounted band shows", async () => {
        const media = installFakeMedia();
        media.microphoneAnswer = "denied";
        const api = bindStepRecordingApi(media);
        renderTwoBandsAndTheButton();
        fireEvent.click(screen.getByTestId("agent-voice-chat-start"));
        await settleVoiceCall();
        expect(api.callsOf("openSession")).toHaveLength(0);
        for (const bandTestId of ["chat-band", "page-band"]) {
            expect(screen.getByTestId(bandTestId)).toHaveAttribute("data-phase", "failed");
            expect(screen.getByTestId(`${bandTestId}-failure`)).toHaveTextContent("the assistant needs your microphone. Allow microphone access in your browser, then try again.");
        }
    });
    test("a refusal of open (no AI budget) is the failed call every mounted band shows", async () => {
        const media = installFakeMedia();
        const api = bindStepRecordingApi(media);
        api.openSessionAnswers.push(() => Promise.reject(new AgentVoiceApiError("no_ai_budget", 403)));
        renderTwoBandsAndTheButton();
        fireEvent.click(screen.getByTestId("agent-voice-chat-start"));
        await settleVoiceCall();
        expect(readAgentVoiceCallSnapshot()).toMatchObject({ phase: "failed", agentSpaceId: 3 });
        for (const bandTestId of ["chat-band", "page-band"]) {
            expect(screen.getByTestId(`${bandTestId}-failure`)).toHaveTextContent("Your AI budget is used up, so the assistant cannot talk right now.");
        }
    });
});
