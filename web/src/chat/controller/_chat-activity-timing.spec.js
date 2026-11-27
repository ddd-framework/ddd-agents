import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createElement } from "react";
import { act, renderHook } from "@testing-library/react";
import { AgentsConversationClientProvider } from "../../client/AgentsConversationClientContext";
import { BACKLOG_LINE_PACE_MS, EMPTY_ACTIVITY_TICKER_QUEUE, LINE_PACE_MS, msUntilNextActivityLine } from "./activity-ticker";
import { DEFAULT_AGENT_CHAT_ACTIVITY_TIMING, resolveAgentChatActivityTiming } from "./chat-activity-timing";
import { ACTIVITY_REQUEST_WINDOW_MS, useConversationActivity } from "./use-conversation-activity";
describe("resolveAgentChatActivityTiming", () => {
    it("keeps the package's values when nothing is set", () => {
        expect(resolveAgentChatActivityTiming()).toEqual(DEFAULT_AGENT_CHAT_ACTIVITY_TIMING);
        expect(DEFAULT_AGENT_CHAT_ACTIVITY_TIMING).toEqual({
            activityRequestWindowMs: 3000,
            runningFloorMs: 4000,
            linePaceMs: 2200,
            backlogLinePaceMs: 1400,
            successBeatMs: 1400,
        });
    });
    it("takes each field from the chat, then the environment, then the package; an unusable value counts as unset", () => {
        const resolved = resolveAgentChatActivityTiming({ activityRequestWindowMs: 250, linePaceMs: Number.NaN }, { activityRequestWindowMs: 1000, linePaceMs: 900, successBeatMs: -1 });
        expect(resolved).toEqual({ ...DEFAULT_AGENT_CHAT_ACTIVITY_TIMING, activityRequestWindowMs: 250, linePaceMs: 900 });
    });
});
describe("the paces of the ticker", () => {
    const queueWithWaitingLines = (count) => ({
        ...EMPTY_ACTIVITY_TICKER_QUEUE,
        lastShownAt: 1000,
        waitingLines: Array.from({ length: count }, (_, index) => ({
            toolCallMessageId: index + 1,
            state: "running",
            line: `line ${index}`,
            kind: "tool",
            helperIndex: null,
        })),
    });
    it("wait the package's paces by default and the host's when given", () => {
        expect(msUntilNextActivityLine(queueWithWaitingLines(1), 1000)).toBe(LINE_PACE_MS);
        expect(msUntilNextActivityLine(queueWithWaitingLines(3), 1000)).toBe(BACKLOG_LINE_PACE_MS);
        expect(msUntilNextActivityLine(queueWithWaitingLines(1), 1000, 300, 100)).toBe(300);
        expect(msUntilNextActivityLine(queueWithWaitingLines(3), 1000, 300, 100)).toBe(100);
    });
});
describe("the activity read window", () => {
    const getConversationActivityMock = vi.fn();
    const conversationClient = { getConversationActivity: getConversationActivityMock };
    const withConversationClient = ({ children }) => createElement(AgentsConversationClientProvider, { client: conversationClient }, children);
    const runningAnswer = () => Promise.resolve({ turnMessageId: 77, turnRunning: true, elements: [] });
    beforeEach(() => {
        vi.useFakeTimers();
        getConversationActivityMock.mockReset();
        getConversationActivityMock.mockImplementation(runningAnswer);
    });
    afterEach(() => {
        vi.useRealTimers();
    });
    const countReadsAfterASignalTick = async (activityRequestWindowMs) => {
        const { rerender } = renderHook((props) => useConversationActivity({ conversationId: 5, runStatus: "running", signalVersion: props.signalVersion, activityRequestWindowMs }), { initialProps: { signalVersion: 0 }, wrapper: withConversationClient });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });
        rerender({ signalVersion: 1 });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        return getConversationActivityMock.mock.calls.length;
    };
    it("holds a signal's read for the package's 3 s window, and reads at once under a host's shorter window", async () => {
        expect(ACTIVITY_REQUEST_WINDOW_MS).toBe(3000);
        expect(await countReadsAfterASignalTick()).toBe(1);
        getConversationActivityMock.mockClear();
        expect(await countReadsAfterASignalTick(200)).toBe(2);
    });
});
