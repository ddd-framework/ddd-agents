import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { IDLE_AGENT_VOICE_CALL } from "../call/agent-voice-call-store";
import { RecordingAgentVoiceApi } from "../call/agent-voice-call-store.spec-helper";
import { installFakeMedia, resetAgentVoiceCall, startLiveAgentVoiceCall } from "../agent-voice-components.spec-helper";
import { AgentVoiceCallBand, AgentVoiceCallBandView } from "./AgentVoiceCallBand";
/**
 * The band's two additions: the application's own controls (`actions`), shown beside the band's own only while the
 * band shows, and `testId` with `${testId}-<part>` on the inner elements, the fixed ids staying the default.
 */
const LIVE_CALL = { ...IDLE_AGENT_VOICE_CALL, phase: "live", callId: 9, connectedAtMs: 1000 };
const ENDED_CALL = { ...LIVE_CALL, phase: "ended", endedAtMs: 61000 };
const FAILED_CALL = {
    ...IDLE_AGENT_VOICE_CALL,
    phase: "failed",
    failureTextKey: "%agentName% needs your microphone. Allow microphone access in your browser, then try again.",
};
const RING = {
    callId: 12,
    answerToken: "token-12",
    ringExpiresAt: "2026-11-25T20:00:30Z",
    ringExpiresAtUnixMs: 1764100830000,
    callerLabelKey: "%agentName% would like to talk",
    callerContext: {},
};
const ringing = (changes = {}) => ({
    ring: RING,
    isAnswering: false,
    onAccept: () => undefined,
    onDecline: () => undefined,
    ...changes,
});
const OWN_ACTION = (<button type="button" data-testid="own-action">
    Open in a window
  </button>);
/** The test ids rendered in the document, in order. */
const renderedTestIds = () => Array.from(document.querySelectorAll("[data-testid]")).map((element) => element.getAttribute("data-testid") ?? "");
const SHOWN_STATES = [
    { name: "a live call", call: LIVE_CALL },
    { name: "a ring", call: IDLE_AGENT_VOICE_CALL, incoming: ringing() },
    { name: "a refused answer", call: IDLE_AGENT_VOICE_CALL, incoming: ringing({ ring: undefined, refusal: "answeredElsewhere" }) },
    { name: "an ended call", call: ENDED_CALL },
    { name: "a failed call", call: FAILED_CALL },
];
describe("AgentVoiceCallBandView actions", () => {
    afterEach(cleanup);
    test.each(SHOWN_STATES)("renders the application's controls while the band shows $name", ({ call, incoming }) => {
        render(<AgentVoiceCallBandView call={call} elapsedSeconds={60} onStartVoiceChat={() => undefined} incoming={incoming} actions={OWN_ACTION}/>);
        const band = screen.getByTestId("agent-voice-call-band");
        const ownAction = screen.getByTestId("own-action");
        expect(band).toContainElement(ownAction);
        expect(screen.getByTestId("agent-voice-call-band-actions")).toContainElement(ownAction);
    });
    test("places them before the band's own buttons, in the same row", () => {
        render(<AgentVoiceCallBandView call={LIVE_CALL} elapsedSeconds={60} onStartVoiceChat={() => undefined} actions={OWN_ACTION}/>);
        const row = screen.getByTestId("agent-voice-call-mute").parentElement;
        expect(row).toContainElement(screen.getByTestId("own-action"));
        const order = renderedTestIds();
        expect(order.indexOf("own-action")).toBeLessThan(order.indexOf("agent-voice-call-mute"));
        expect(order.indexOf("agent-voice-call-mute")).toBeLessThan(order.indexOf("agent-voice-call-end"));
    });
    test("renders nothing of them while the band is hidden (no call, no ring)", () => {
        const { container } = render(<AgentVoiceCallBandView call={IDLE_AGENT_VOICE_CALL} elapsedSeconds={0} onStartVoiceChat={() => undefined} actions={OWN_ACTION}/>);
        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByTestId("own-action")).toBeNull();
    });
    test.each(SHOWN_STATES)("without actions $name renders exactly the band of before", ({ call, incoming }) => {
        render(<AgentVoiceCallBandView call={call} elapsedSeconds={60} onStartVoiceChat={() => undefined} incoming={incoming}/>);
        const withoutActions = document.body.innerHTML;
        cleanup();
        render(<AgentVoiceCallBandView call={call} elapsedSeconds={60} onStartVoiceChat={() => undefined} incoming={incoming} actions={null}/>);
        expect(screen.queryByTestId("agent-voice-call-band-actions")).toBeNull();
        expect(document.body.innerHTML).toBe(withoutActions);
    });
});
describe("AgentVoiceCallBandView test ids", () => {
    afterEach(cleanup);
    const DEFAULT_IDS = [
        {
            name: "a live call",
            call: LIVE_CALL,
            ids: ["agent-voice-call-band", "agent-voice-call-status", "agent-voice-call-mute", "agent-voice-call-end"],
        },
        {
            name: "a ring",
            call: IDLE_AGENT_VOICE_CALL,
            incoming: ringing(),
            ids: ["agent-voice-call-band", "agent-voice-call-incoming-decline", "agent-voice-call-incoming-accept"],
        },
        {
            name: "a refused answer",
            call: IDLE_AGENT_VOICE_CALL,
            incoming: ringing({ ring: undefined, refusal: "answeredElsewhere" }),
            ids: ["agent-voice-call-band", "agent-voice-call-incoming-refusal"],
        },
        {
            name: "an ended call",
            call: ENDED_CALL,
            ids: [
                "agent-voice-call-band",
                "agent-voice-call-ended-status",
                "agent-voice-call-start-again",
                "agent-voice-call-band-close",
            ],
        },
        {
            name: "a failed call",
            call: FAILED_CALL,
            ids: ["agent-voice-call-band", "agent-voice-call-failure", "agent-voice-call-start-again", "agent-voice-call-band-close"],
        },
    ];
    test.each(DEFAULT_IDS)("keeps the fixed ids without a testId: $name", ({ call, incoming, ids }) => {
        render(<AgentVoiceCallBandView call={call} elapsedSeconds={60} onStartVoiceChat={() => undefined} incoming={incoming}/>);
        expect(renderedTestIds()).toStrictEqual(ids);
    });
    test.each(DEFAULT_IDS)("puts the testId on the band and ${testId}-<part> on every inner element: $name", ({ call, incoming, ids }) => {
        render(<AgentVoiceCallBandView call={call} elapsedSeconds={60} onStartVoiceChat={() => undefined} incoming={incoming} testId="side-band"/>);
        const expectedIds = ids.map((id) => id === "agent-voice-call-band"
            ? "side-band"
            : `side-band-${id.replace(/^agent-voice-call-band-/, "").replace(/^agent-voice-call-/, "")}`);
        expect(renderedTestIds()).toStrictEqual(expectedIds);
    });
    test("the application's controls take ${testId}-actions", () => {
        render(<AgentVoiceCallBandView call={LIVE_CALL} elapsedSeconds={60} onStartVoiceChat={() => undefined} actions={OWN_ACTION} testId="side-band"/>);
        expect(screen.getByTestId("side-band-actions")).toContainElement(screen.getByTestId("own-action"));
    });
});
describe("AgentVoiceCallBand hands actions and testId to the band of the live call", () => {
    afterEach(() => {
        resetAgentVoiceCall();
        cleanup();
        vi.unstubAllGlobals();
    });
    test("shows nothing of them while no call runs, and both once the call is live", async () => {
        const media = installFakeMedia();
        render(<AgentVoiceCallBand actions={OWN_ACTION} testId="side-band"/>);
        expect(screen.queryByTestId("own-action")).toBeNull();
        expect(screen.queryByTestId("side-band")).toBeNull();
        await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), media);
        expect(screen.getByTestId("side-band")).toHaveAttribute("data-phase", "live");
        expect(screen.getByTestId("side-band-actions")).toContainElement(screen.getByTestId("own-action"));
        expect(screen.getByTestId("side-band-end")).toBeInTheDocument();
    });
    test("keeps the fixed ids and adds nothing when the application sets neither", async () => {
        const media = installFakeMedia();
        render(<AgentVoiceCallBand />);
        await startLiveAgentVoiceCall(new RecordingAgentVoiceApi(), media);
        expect(renderedTestIds()).toStrictEqual([
            "agent-voice-call-band",
            "agent-voice-call-status",
            "agent-voice-call-mute",
            "agent-voice-call-end",
        ]);
    });
});
