// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { createAgentsChatStore } from "./create-agents-chat-store";
import { useAgentsChatSelector } from "./use-agents-chat-store";

describe("createAgentsChatStore", () => {
  it("notifies its listeners once per change and not for an event that changes nothing", () => {
    const chatStore = createAgentsChatStore();
    const listener = vi.fn();
    const unsubscribe = chatStore.subscribe(listener);

    chatStore.dispatch({ type: "aiThinkingSet", aiThinking: true });
    chatStore.dispatch({ type: "aiThinkingSet", aiThinking: true });
    expect(listener).toHaveBeenCalledTimes(1);
    expect(chatStore.getState().aiThinking).toBe(true);

    unsubscribe();
    chatStore.dispatch({ type: "aiThinkingSet", aiThinking: false });
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it("hands out a new start token after a reset, so a late answer of the earlier start is dropped", () => {
    const chatStore = createAgentsChatStore();
    chatStore.dispatch({ type: "startRequested", message: { message: "First.", role: "USER" } });
    const firstStartToken = chatStore.getState().pendingStartToken as number;
    chatStore.dispatch({ type: "reset" });
    chatStore.dispatch({ type: "startRequested", message: { message: "Second.", role: "USER" } });
    expect(chatStore.getState().pendingStartToken).not.toBe(firstStartToken);
    chatStore.dispatch({
      type: "startReceived",
      startToken: firstStartToken,
      started: { conversation: { id: 101 } as never, messages: [], reopenedExisting: false },
    });
    expect(chatStore.getState().conversation).toBeNull();
  });

  it("two chat instances never share state", () => {
    const firstChatStore = createAgentsChatStore();
    const secondChatStore = createAgentsChatStore();
    firstChatStore.dispatch({ type: "openingRequestSet", openingRequest: { userMessage: "Publish my business." } });
    expect(secondChatStore.getState().openingRequest.userMessage).toBeNull();
  });

  it("useAgentsChatSelector re-renders on a change and reads the fallback without a store", () => {
    const chatStore = createAgentsChatStore();
    const selectAiThinking = (state: { aiThinking: boolean }) => state.aiThinking;
    const { result } = renderHook(() => useAgentsChatSelector(chatStore, selectAiThinking));
    expect(result.current).toBe(false);
    act(() => chatStore.dispatch({ type: "aiThinkingSet", aiThinking: true }));
    expect(result.current).toBe(true);

    const { result: resultWithoutStore } = renderHook(() => useAgentsChatSelector(null, selectAiThinking, false));
    expect(resultWithoutStore.current).toBe(false);
  });
});
