import { describe, expect, it } from "vitest";
import type { AgentsConversationMessage } from "../../contract/conversation";
import { buildChangedMessagesRequest } from "./changed-messages-request";

const PAGE_SIZE = 50;

const changedMessagesRequest = (highestKnownId: number, fromIndex: number) => ({
  highestKnownId,
  fromIndex,
  pageSize: PAGE_SIZE,
});

const textMessages = (count: number): AgentsConversationMessage[] =>
  Array.from(
    { length: count },
    (_, index) => ({ id: 1000 + index, indexInConversation: index }) as AgentsConversationMessage,
  );

const toolCallMessage = (indexInConversation: number, status: string): AgentsConversationMessage =>
  ({
    id: 3000 + indexInConversation,
    indexInConversation,
    messageType: "TOOL_CALL",
    toolCall: { name: "searchReviews", status },
  }) as unknown as AgentsConversationMessage;

const delegateMessage = (
  indexInConversation: number,
  overrides: Partial<AgentsConversationMessage> = {},
): AgentsConversationMessage =>
  ({
    id: 5000 + indexInConversation,
    indexInConversation,
    messageType: "TOOL_CALL",
    toolCall: { name: "delegate", status: "RUNNING", arguments: { tasks: [{}, {}] } },
    ...overrides,
  }) as unknown as AgentsConversationMessage;

describe("buildChangedMessagesRequest", () => {
  it("asks only for messages newer than the newest loaded one, whatever the history length (AC-34)", () => {
    expect(buildChangedMessagesRequest(textMessages(60), PAGE_SIZE)).toEqual(changedMessagesRequest(1059, 60));
    expect(buildChangedMessagesRequest(textMessages(500), PAGE_SIZE)).toEqual(changedMessagesRequest(1499, 500));
  });

  it("starts at the lowest tool call whose result is still to come — read from toolCall.status, not the row status", () => {
    const messages = [
      ...textMessages(40),
      toolCallMessage(40, "COMPLETED"),
      toolCallMessage(41, "PENDING"),
      toolCallMessage(42, "RUNNING"),
      { id: 5, indexInConversation: 43 } as AgentsConversationMessage,
    ];

    expect(buildChangedMessagesRequest(messages, PAGE_SIZE)).toEqual(changedMessagesRequest(3042, 41));
  });

  it("starts at a signal still waiting for the running turn", () => {
    const waitingSignal = {
      id: 6,
      indexInConversation: 3,
      messageType: "SIGNAL",
      status: "PENDING",
    } as AgentsConversationMessage;

    expect(
      buildChangedMessagesRequest([...textMessages(3), waitingSignal, ...textMessages(6).slice(4)], PAGE_SIZE),
    ).toEqual(changedMessagesRequest(1005, 3));
  });

  it("never starts below the newest page, so a row a dead worker left pending cannot pin it", () => {
    const messages = [...textMessages(100)];
    messages[10] = toolCallMessage(10, "PENDING");

    expect(buildChangedMessagesRequest(messages, PAGE_SIZE)).toEqual(changedMessagesRequest(3010, 50));
  });

  it("also asks for rows written after the newest loaded one, by id — their index may lie below it or be reused", () => {
    const messages = [
      toolCallMessage(3, "COMPLETED"),
      { id: 3005, indexInConversation: 5, messageType: "TOOL_MESSAGE" } as AgentsConversationMessage,
    ];

    expect(buildChangedMessagesRequest(messages, PAGE_SIZE)).toEqual(changedMessagesRequest(3005, 6));
  });

  it("ignores messages without an index and asks for nothing while nothing is loaded", () => {
    expect(buildChangedMessagesRequest(null, PAGE_SIZE)).toBeNull();
    expect(buildChangedMessagesRequest([{ message: "optimistic" } as AgentsConversationMessage], PAGE_SIZE)).toBeNull();
    expect(
      buildChangedMessagesRequest([...textMessages(3), { message: "optimistic" } as AgentsConversationMessage], PAGE_SIZE),
    ).toEqual(changedMessagesRequest(1002, 3));
  });

  it("starts at a delegation whose children are still working", () => {
    const inFlight = delegateMessage(3, {
      delegatedConversations: [
        { id: 1, runState: "RUNNING" },
        { id: 2, runState: "COMPLETED" },
      ],
    });

    expect(
      buildChangedMessagesRequest(
        [...textMessages(3), inFlight, { id: 9, indexInConversation: 4 } as AgentsConversationMessage],
        PAGE_SIZE,
      ),
    ).toEqual(changedMessagesRequest(5003, 3));
  });

  it("lets go of a delegate call left RUNNING once all its children finished", () => {
    const staleDelegate = delegateMessage(3, {
      delegatedConversations: [
        { id: 1, runState: "COMPLETED" },
        { id: 2, runState: "FAILED" },
      ],
    });

    expect(buildChangedMessagesRequest([...textMessages(3), staleDelegate], PAGE_SIZE)).toEqual(
      changedMessagesRequest(5003, 4),
    );
  });

  it("lets go of a settled delegation (AC-36.3)", () => {
    const settled = delegateMessage(3, {
      toolCall: { name: "delegate", status: "COMPLETED" } as never,
      delegatedConversations: [
        { id: 1, runState: "COMPLETED" },
        { id: 2, runState: "COMPLETED" },
      ],
    });

    expect(buildChangedMessagesRequest([...textMessages(3), settled], PAGE_SIZE)).toEqual(
      changedMessagesRequest(5003, 4),
    );
  });
});
