import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test } from "vitest";
import { RecordingAgentVoiceApi } from "../call/agent-voice-call-store.spec-helper";
import { settleVoiceCall } from "../agent-voice-components.spec-helper";
import { AgentVoiceCallProvider } from "../AgentVoiceCallProvider/AgentVoiceCallProvider";
import { AgentVoiceIncomingCall } from "./AgentVoiceIncomingCall";
/**
 * The incoming call's additions: `zIndex` over the custom property's stacking order, and `testId` with
 * `${testId}-<part>` on the inner elements and the refusal card; the fixed ids by default.
 */
/** A ring source that hands every subscriber the one ring it holds. */
class OneRingSource {
    ring;
    constructor(ring) {
        this.ring = ring;
    }
    subscribe(onRing) {
        onRing(this.ring);
        return () => undefined;
    }
}
/** Each spec rings with its own call: an answered or declined call stays handled in the module's ring store. */
const ringOfCall = (callId) => ({
    callId,
    answerToken: `token-${callId}`,
    ringExpiresAt: new Date(Date.now() + 60000).toISOString(),
    ringExpiresAtUnixMs: Date.now() + 60000,
    callerLabelKey: "%agentName% would like to talk",
    callerContext: {},
});
const renderIncomingCall = (callId, props = {}, api = new RecordingAgentVoiceApi()) => render(<AgentVoiceCallProvider api={api} ringSource={new OneRingSource(ringOfCall(callId))}>
      <AgentVoiceIncomingCall resolveCallerLabel={() => ({ text: "Your assistant would like to talk", detail: "Main office" })} {...props}/>
    </AgentVoiceCallProvider>);
const renderedTestIds = () => Array.from(document.querySelectorAll("[data-testid]")).map((element) => element.getAttribute("data-testid") ?? "");
describe("AgentVoiceIncomingCall", () => {
    afterEach(cleanup);
    test("keeps the fixed ids and the custom property's stacking order without the new props", () => {
        renderIncomingCall(101);
        expect(renderedTestIds()).toStrictEqual([
            "agent-voice-incoming-call",
            "agent-voice-incoming-call-detail",
            "agent-voice-incoming-call-decline",
            "agent-voice-incoming-call-accept",
        ]);
        expect(screen.getByTestId("agent-voice-incoming-call")).not.toHaveAttribute("style");
    });
    test("puts the testId on the card and ${testId}-<part> on every inner element", () => {
        renderIncomingCall(102, { testId: "ring-card" });
        expect(renderedTestIds()).toStrictEqual(["ring-card", "ring-card-detail", "ring-card-decline", "ring-card-accept"]);
        expect(screen.getByTestId("ring-card")).toHaveAttribute("role", "alertdialog");
    });
    test("sets the card's stacking order from zIndex", () => {
        renderIncomingCall(103, { zIndex: 1300 });
        expect(screen.getByTestId("agent-voice-incoming-call").style.zIndex).toBe("1300");
    });
    test("a zIndex of 0 is a stacking order too", () => {
        renderIncomingCall(104, { zIndex: 0 });
        expect(screen.getByTestId("agent-voice-incoming-call").style.zIndex).toBe("0");
    });
    // Last: the refusal stays in the module's ring store for a while after the spec.
    test("the card of a refused answer takes ${testId}-refusal and the zIndex", async () => {
        const api = new RecordingAgentVoiceApi();
        api.ringAnswers.push(async () => ({ won: false }));
        renderIncomingCall(105, { testId: "ring-card", zIndex: 1300 }, api);
        act(() => {
            fireEvent.click(screen.getByTestId("ring-card-accept"));
        });
        await settleVoiceCall();
        const refusalCard = screen.getByTestId("ring-card-refusal");
        expect(refusalCard).toHaveAttribute("role", "status");
        expect(refusalCard.style.zIndex).toBe("1300");
        expect(screen.queryByTestId("agent-voice-incoming-call-refusal")).toBeNull();
    });
});
