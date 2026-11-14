import { describe, expect, it } from "vitest";
import type { AgentsConversationMessage } from "../../contract/conversation";
import { countOutstandingChildren, deriveAgentActivity } from "./derive-agent-activity";

/** A fan-out is ONE message with N children — the activity line counts the children still working, not the messages. */
const delegateMessage = (overrides: Partial<AgentsConversationMessage>): AgentsConversationMessage =>
  ({
    indexInConversation: 5,
    messageType: "TOOL_CALL",
    toolCall: { name: "delegate", status: "RUNNING", arguments: { tasks: [{}, {}, {}] } },
    ...overrides,
  }) as unknown as AgentsConversationMessage;

describe("deriveAgentActivity — outstanding sub-agents", () => {
  it("counts the expanded children that are not terminal", () => {
    const message = delegateMessage({
      delegatedConversations: [{ runState: "COMPLETED" }, { runState: "RUNNING" }, { runState: "QUEUED" }, { runState: "FAILED" }] as never,
    });
    expect(countOutstandingChildren(message)).toBe(2);
    expect(deriveAgentActivity([message], "running")).toMatchObject({
      active: true,
      replacements: { count: 2 },
      context: "other",
    });
  });

  it("falls back to the declared task count of an unresolved delegate call, and to one without tasks", () => {
    expect(countOutstandingChildren(delegateMessage({}))).toBe(3);
    expect(
      countOutstandingChildren(
        delegateMessage({ toolCall: { name: "delegate", status: "RUNNING", arguments: {} } } as never),
      ),
    ).toBe(1);
  });

  it("a resolved fan-out whose children all finished counts nothing", () => {
    const message = delegateMessage({
      toolCall: { name: "writeAllPendingPages", status: "COMPLETED" } as never,
      delegatedConversations: [{ runState: "COMPLETED" }, { runState: "COMPLETED" }] as never,
    });
    expect(countOutstandingChildren(message)).toBe(0);
    expect(deriveAgentActivity([message], "idle")).toEqual({ active: false, label: "" });
  });

  it("a delegate call left RUNNING whose expanded children all finished is resolved in effect — no spinner, even as last message", () => {
    const staleDelegate = delegateMessage({
      delegatedConversations: [{ runState: "COMPLETED" }, { runState: "COMPLETED" }, { runState: "FAILED" }] as never,
    });
    expect(countOutstandingChildren(staleDelegate)).toBe(0);
    expect(deriveAgentActivity([staleDelegate], "idle")).toEqual({ active: false, label: "" });
    // The branch that kept the chat "thinking": signal not idle, no outstanding children, the stale call is the
    // LAST message — it must not read as a running tool.
    expect(deriveAgentActivity([staleDelegate], "running")).toEqual({ active: true, label: "" });
    expect(deriveAgentActivity([staleDelegate], null)).toEqual({ active: false, label: "" });
    // Not expanded → the stored status still counts (an empty expansion proves nothing).
    expect(deriveAgentActivity([delegateMessage({})], "idle")).toMatchObject({
      active: true,
      replacements: { count: 3 },
    });
  });

  it("a non-delegate tool result that spawned children counts them while they run", () => {
    const message = delegateMessage({
      toolCall: { name: "startLargeEdit", status: "COMPLETED" } as never,
      delegatedConversations: Array.from({ length: 8 }, () => ({ runState: "RUNNING" })) as never,
    });
    expect(deriveAgentActivity([message], "running")).toMatchObject({ replacements: { count: 8 } });
  });

  // A NEW row (one `delegate` call = one sub-agent) has no `tasks[]` and no `delegatedConversations`
  // until the record's child is expanded; the record's own run is the truth of whether it is outstanding.
  it("counts one outstanding sub-agent for a NEW row whose current run is still queued or running", () => {
    const running = delegateMessage({
      toolCall: { name: "delegate", status: "RUNNING", arguments: { label: "Services page" } } as never,
      subAgent: { currentRunNumber: 1, runs: [{ runNumber: 1, status: "RUNNING" }] } as never,
    });
    expect(countOutstandingChildren(running)).toBe(1);

    const queued = delegateMessage({
      toolCall: { name: "delegate", status: "PENDING", arguments: { label: "Services page" } } as never,
      subAgent: { currentRunNumber: 1, runs: [{ runNumber: 1, status: "QUEUED" }] } as never,
    });
    expect(countOutstandingChildren(queued)).toBe(1);
  });

  it("counts nothing for a NEW row whose current run already ended", () => {
    const ended = delegateMessage({
      toolCall: { name: "delegate", status: "COMPLETED", arguments: { label: "Services page" } } as never,
      subAgent: { currentRunNumber: 1, runs: [{ runNumber: 1, status: "COMPLETED" }] } as never,
    });
    expect(countOutstandingChildren(ended)).toBe(0);
  });

  it("a NEW row's continuation (run 2) counts as one outstanding sub-agent, not the task count", () => {
    const continued = delegateMessage({
      toolCall: { name: "delegate", status: "RUNNING" } as never,
      subAgent: {
        currentRunNumber: 2,
        runs: [
          { runNumber: 1, status: "COMPLETED" },
          { runNumber: 2, status: "RUNNING" },
        ],
      } as never,
    });
    expect(countOutstandingChildren(continued)).toBe(1);
  });
});

describe("deriveAgentActivity — thinking face starts on send, not on the server's running ping", () => {
  const assistantReply = (overrides: Partial<AgentsConversationMessage>): AgentsConversationMessage =>
    ({
      indexInConversation: 4,
      role: "ASSISTANT",
      messageType: "TEXT",
      ...overrides,
    }) as unknown as AgentsConversationMessage;
  const ownerMessage = (overrides: Partial<AgentsConversationMessage>): AgentsConversationMessage =>
    ({ role: "USER", messageType: "TEXT", ...overrides }) as unknown as AgentsConversationMessage;

  it("an optimistic owner row (no indexInConversation yet) sorts to the tail and starts the face at once", () => {
    // The previous turn's answer carries a real index; the just-sent bubble has none yet (conversations-message-post.case.ts).
    const messages = [assistantReply({ indexInConversation: 4 }), ownerMessage({})];
    // runStatus is still the PREVIOUS turn's rest state — the server hasn't flipped to "running" yet.
    expect(deriveAgentActivity(messages, "idle")).toEqual({ active: true, label: "" });
  });

  it("a saved owner row still awaiting its first response starts the face even while runStatus reads idle", () => {
    const messages = [assistantReply({ indexInConversation: 4 }), ownerMessage({ indexInConversation: 5 })];
    expect(deriveAgentActivity(messages, "idle")).toEqual({ active: true, label: "" });
  });

  it("no messages at all stays idle", () => {
    expect(deriveAgentActivity([], "idle")).toEqual({ active: false, label: "" });
    expect(deriveAgentActivity(null, "idle")).toEqual({ active: false, label: "" });
  });
});
