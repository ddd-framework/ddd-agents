import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { DEFAULT_AGENT_CHAT_ACTIVITY_TIMING } from "../controller/chat-activity-timing";
import { createAgentsChatStore } from "../store/create-agents-chat-store";
import { AgentChatActivityIndicator } from "../AgentChatActivityIndicator/AgentChatActivityIndicator";
import { ACTIVITY_STACK_VISIBLE_LINES, AgentChatActivityStack } from "./AgentChatActivityStack";
const line = (toolCallMessageId, sentence) => ({
    toolCallMessageId,
    state: "running",
    line: sentence,
    kind: "tool",
    helperIndex: null,
});
const ticker = (phase, lines) => ({
    phase,
    currentTurn: lines.length > 0
        ? { turnMessageId: 7, phase: phase === "done" ? "done" : "live", lines, stepCount: 0, helperCount: 0, liveSince: 0 }
        : null,
    historyTurns: [],
});
/** The chat's state the activity components may read; these specs need none of it. */
const chatProps = { chatStore: createAgentsChatStore(), unansweredWidgetMessageId: null };
const renderStack = (activityTicker, extra = {}) => render(<AgentChatActivityStack active={extra.active ?? true} failed={extra.failed ?? false} activityTicker={activityTicker} isAgentWorkingOnTask agentSpaceKey={1} {...chatProps} activityTiming={{ ...DEFAULT_AGENT_CHAT_ACTIVITY_TIMING, linePaceMs: 2000, successBeatMs: 900 }}/>);
describe("AgentChatActivityStack", () => {
    it("shows the calm dots and the waiting text before the first sentence", () => {
        const { container } = renderStack(ticker("live", []));
        expect(container.querySelector('[data-agents-activity-dots="pulse"]')).not.toBeNull();
        expect(screen.getByTestId("agent-chat-activity-stack-status").textContent).toBe("Working on it…");
    });
    it("stacks one sentence per tool call, the newest at rank 0, and keeps only the visible ranks", () => {
        const sentences = ["Reading A", "Reading B", "Reading C", "Reading D", "Reading E"].map((sentence, index) => line(index + 1, sentence));
        const { container } = renderStack(ticker("live", sentences));
        const rows = Array.from(container.querySelectorAll("li"));
        expect(rows).toHaveLength(ACTIVITY_STACK_VISIBLE_LINES);
        expect(rows.map((row) => row.getAttribute("data-rank"))).toEqual(["3", "2", "1", "0"]);
        expect(rows[3].textContent).toBe("Reading E");
        expect(container.querySelector("[data-agents-activity-dots]")).toBeNull();
        expect(container.textContent).not.toContain("Working on it");
    });
    it("hides the moving layer and announces the newest sentence politely", () => {
        const { container } = renderStack(ticker("live", [line(1, "Reading A"), line(2, "Reading B")]));
        expect(container.querySelector('[aria-hidden="true"]')?.querySelector("li")).not.toBeNull();
        const statusRegion = screen.getByRole("status");
        expect(statusRegion.getAttribute("aria-live")).toBe("polite");
        expect(statusRegion.textContent).toBe("Reading B");
    });
    it("fades out on the done beat with the chat's timing, and shows nothing between turns or after a failure", () => {
        const { container, rerender } = renderStack(ticker("done", [line(1, "Found A")]), { active: false });
        const stack = screen.getByTestId("agent-chat-activity-stack");
        expect(stack.getAttribute("data-phase")).toBe("done");
        expect(stack.style.getPropertyValue("--agents-activity-stack-fade-duration")).toBe("900ms");
        expect(stack.style.getPropertyValue("--agents-activity-stack-move-duration")).toBe("500ms");
        rerender(<AgentChatActivityStack active={false} failed={false} activityTicker={ticker("idle", [])} isAgentWorkingOnTask={false} agentSpaceKey={1} {...chatProps}/>);
        expect(container.firstChild).toBeNull();
        rerender(<AgentChatActivityStack active failed activityTicker={ticker("live", [line(1, "A")])} isAgentWorkingOnTask agentSpaceKey={1} {...chatProps}/>);
        expect(container.firstChild).toBeNull();
    });
});
describe("AgentChatActivityIndicator dots", () => {
    it("keeps the bouncing dots by default and offers the calm ones", () => {
        const props = { active: true, failed: false, activityTicker: ticker("live", []), isAgentWorkingOnTask: true, agentSpaceKey: 1, ...chatProps };
        const { container, rerender } = render(<AgentChatActivityIndicator {...props}/>);
        expect(container.querySelector('[data-agents-activity-dots="bounce"]')).not.toBeNull();
        rerender(<AgentChatActivityIndicator {...props} dotsVariant="pulse"/>);
        expect(container.querySelector('[data-agents-activity-dots="pulse"]')).not.toBeNull();
    });
});
