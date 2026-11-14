import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createElement, type ReactNode } from "react";
import { act, renderHook } from "@testing-library/react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import { AgentsConversationClientProvider } from "../../client/AgentsConversationClientContext";
import type { ConversationActivity } from "../../contract/conversation";
import {
  MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED,
  RUNNING_FLOOR_MS,
  useConversationActivity,
} from "./use-conversation-activity";

/** The conversation client's activity read; every other port function stays unused by the hook. */
const getConversationActivityMock = vi.fn<AgentsConversationClient["getConversationActivity"]>();
const conversationClient = { getConversationActivity: getConversationActivityMock } as unknown as AgentsConversationClient;
const withConversationClient = ({ children }: { children: ReactNode }) =>
  createElement(AgentsConversationClientProvider, { client: conversationClient }, children);

const activityAnswer = (
  turnRunning: boolean,
  state: "running" | "finished",
  line: string,
): Promise<ConversationActivity> =>
  Promise.resolve({
    turnMessageId: 77,
    turnRunning,
    elements: [{ toolCallMessageId: 1, batchId: null, state, kind: "tool", childCount: null, helperIndex: null, line }],
  });
const RUNNING_TURN = () => activityAnswer(true, "running", "Looking for A");
const ENDED_TURN = () => activityAnswer(false, "finished", "Found A");
const FAILED_REQUEST = () => Promise.reject(new Error("network"));

type HookProps = { conversationId: number; runStatus: string | null; signalVersion: number };

const flushRequests = async (ms = 0) => {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
};

describe("useConversationActivity", () => {
  let tabHidden = false;

  beforeEach(() => {
    vi.useFakeTimers();
    getConversationActivityMock.mockReset();
    tabHidden = false;
    Object.defineProperty(document, "hidden", { configurable: true, get: () => tabHidden });
  });

  afterEach(() => {
    vi.useRealTimers();
    delete (document as { hidden?: boolean }).hidden;
  });

  const renderRunningTurn = async () => {
    getConversationActivityMock.mockImplementationOnce(RUNNING_TURN);
    const hook = renderHook((props: HookProps) => useConversationActivity(props), {
      initialProps: { conversationId: 5, runStatus: "running", signalVersion: 0 },
      wrapper: withConversationClient,
    });
    await flushRequests();
    expect(hook.result.current.running).toBe(true);
    return hook;
  };

  it("asks the client for the activity of the conversation, then for the pinned turn", async () => {
    const { rerender } = await renderRunningTurn();
    expect(getConversationActivityMock).toHaveBeenLastCalledWith(5, null);

    getConversationActivityMock.mockImplementationOnce(ENDED_TURN);
    rerender({ conversationId: 5, runStatus: "idle", signalVersion: 0 });
    await flushRequests();
    expect(getConversationActivityMock).toHaveBeenLastCalledWith(5, 77);
  });

  it("asks again on tab return when the turn ended while the tab was hidden", async () => {
    const { result, rerender } = await renderRunningTurn();

    tabHidden = true;
    rerender({ conversationId: 5, runStatus: "idle", signalVersion: 0 });
    await flushRequests(3 * RUNNING_FLOOR_MS);
    // Nothing is requested for a hidden tab, so the ended turn is not known yet.
    expect(getConversationActivityMock).toHaveBeenCalledTimes(1);
    expect(result.current.running).toBe(true);

    getConversationActivityMock.mockImplementationOnce(ENDED_TURN);
    tabHidden = false;
    document.dispatchEvent(new Event("visibilitychange"));
    await flushRequests();
    expect(getConversationActivityMock).toHaveBeenCalledTimes(2);
    expect(result.current.running).toBe(false);
    expect(result.current.elements[0]).toMatchObject({ state: "finished", line: "Found A" });
  });

  it("asks again on the floor when the request at the turn's end failed, then rests", async () => {
    const { result, rerender } = await renderRunningTurn();

    getConversationActivityMock.mockImplementationOnce(FAILED_REQUEST);
    rerender({ conversationId: 5, runStatus: "idle", signalVersion: 0 });
    await flushRequests();
    expect(getConversationActivityMock).toHaveBeenCalledTimes(2);
    expect(result.current.running).toBe(true);

    getConversationActivityMock.mockImplementationOnce(ENDED_TURN);
    await flushRequests(RUNNING_FLOOR_MS);
    expect(getConversationActivityMock).toHaveBeenCalledTimes(3);
    expect(result.current.running).toBe(false);

    await flushRequests(3 * RUNNING_FLOOR_MS);
    expect(getConversationActivityMock).toHaveBeenCalledTimes(3);
  });

  it(`ends the turn after ${MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED} failed requests in a row once the signal ended`, async () => {
    const { result, rerender } = await renderRunningTurn();

    getConversationActivityMock.mockImplementation(FAILED_REQUEST);
    rerender({ conversationId: 5, runStatus: "idle", signalVersion: 0 });
    await flushRequests();
    expect(result.current.running).toBe(true);

    await flushRequests((MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED - 1) * RUNNING_FLOOR_MS);
    expect(getConversationActivityMock).toHaveBeenCalledTimes(1 + MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED);
    expect(result.current.running).toBe(false);

    await flushRequests(3 * RUNNING_FLOOR_MS);
    expect(getConversationActivityMock).toHaveBeenCalledTimes(1 + MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED);
  });

  it.each([
    ["answers that a step still runs", RUNNING_TURN],
    ["fails", FAILED_REQUEST],
  ])("ends a failed run with its last request when that request %s", async (_, lastAnswer) => {
    const { result, rerender } = await renderRunningTurn();

    getConversationActivityMock.mockImplementationOnce(lastAnswer);
    rerender({ conversationId: 5, runStatus: "failed", signalVersion: 0 });
    await flushRequests();
    expect(getConversationActivityMock).toHaveBeenCalledTimes(2);
    expect(result.current.running).toBe(false);

    await flushRequests(3 * RUNNING_FLOOR_MS);
    expect(getConversationActivityMock).toHaveBeenCalledTimes(2);
  });
});
