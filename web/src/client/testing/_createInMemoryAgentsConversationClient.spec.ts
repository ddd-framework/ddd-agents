import { afterEach, describe, expect, it, vi } from "vitest";
import type {
  AgentsConversation,
  AgentsConversationMessage,
  ConversationChangeSignal,
  ConversationSubscriptionEndReason,
} from "../../contract/conversation";
import { AgentsClientError } from "../../contract/conversation";
import {
  createInMemoryAgentsConversationClient,
  type InMemoryAgentsConversationClient,
} from "./createInMemoryAgentsConversationClient";

const lastOf = <T>(items: T[]): T | undefined => items[items.length - 1];

/** The client's subscription; fails the test for a client built without one. */
const subscribe = (
  client: InMemoryAgentsConversationClient,
  conversationId: number,
  onChange: (signal: ConversationChangeSignal) => void,
  onEnded: (reason: ConversationSubscriptionEndReason) => void,
): (() => void) => {
  if (!client.subscribeToConversationChanges) throw new Error("The client was built without a subscription.");
  return client.subscribeToConversationChanges(conversationId, onChange, onEnded);
};

const buildConversation = (fields: Partial<AgentsConversation> & { id: number }): AgentsConversation => ({
  agentSpaceKey: 7,
  runState: "IDLE",
  agentTurnOwedAt: null,
  title: `Conversation ${fields.id}`,
  titleManuallyEdited: false,
  summary: null,
  created: null,
  updated: null,
  purposeName: null,
  initiatorType: null,
  scheduleId: null,
  entryPointKey: null,
  progressSteps: [],
  ...fields,
});

const buildMessage = (
  id: number,
  conversationId: number,
  indexInConversation: number,
  role: "USER" | "ASSISTANT",
  message: string,
): AgentsConversationMessage => ({
  id,
  conversationId,
  indexInConversation,
  role,
  messageType: "TEXT",
  status: "COMPLETED",
  authorType: role,
  signalSource: null,
  message,
  createdDate: null,
  toolCall: null,
  delegatedConversations: [],
  subAgent: null,
});

/** Conversation 1 with six alternating messages (ids 101–106, indexes 0–5); conversation 2 untitled; 3 in another agent space. */
const buildClient = () =>
  createInMemoryAgentsConversationClient({
    conversations: [
      {
        conversation: buildConversation({ id: 1, entryPointKey: "hub.starter.R2" }),
        messages: [0, 1, 2, 3, 4, 5].map((index) =>
          buildMessage(101 + index, 1, index, index % 2 === 0 ? "USER" : "ASSISTANT", `Message about reviews ${index}`),
        ),
      },
      { conversation: buildConversation({ id: 2, title: null }), messages: [] },
      { conversation: buildConversation({ id: 3, agentSpaceKey: 8 }), messages: [] },
    ],
    scriptedAssistantAnswers: ["First answer", "Second answer"],
  });

describe("createInMemoryAgentsConversationClient", () => {
  it("lists the conversations of an agent space newest first, untitled ones only on request", async () => {
    const client = buildClient();
    expect((await client.listConversations(7)).map((conversation) => conversation.id)).toEqual([1]);
    expect(
      (await client.listConversations(7, { includeUnsummarized: true })).map((conversation) => conversation.id),
    ).toEqual([2, 1]);
  });

  it("reads the newest page, an older page and the changed tail", async () => {
    const client = buildClient();
    const newestPage = await client.getNewestMessagesPage(1, 4);
    expect(newestPage.messages.map((message) => message.indexInConversation)).toEqual([5, 4, 3, 2]);
    expect(newestPage.hasMoreOlder).toBe(true);

    const olderPage = await client.getOlderMessagesPage(1, 2, 4);
    expect(olderPage.messages.map((message) => message.indexInConversation)).toEqual([1, 0]);
    expect(olderPage.hasMoreOlder).toBe(false);

    const changedTail = await client.getChangedMessages(1, { highestKnownId: 105, fromIndex: 3 });
    expect(changedTail.messages.map((message) => message.id)).toEqual([106, 105, 104]);
  });

  it("stores a post with the next scripted answer, and the answer reaches a subscriber through emit()", async () => {
    const client = buildClient();
    const receivedSignals: ConversationChangeSignal[] = [];
    const unsubscribe = subscribe(client, 1, (signal) => receivedSignals.push(signal), vi.fn());
    expect(receivedSignals).toEqual([{ changeCounter: 0, lastMessageId: 106, runStatus: "idle", summaryUpdatedAt: null }]);

    const posted = await client.postMessage(1, {
      text: "Show my reviews",
      attachments: [{ fileId: 9, fileName: "logo.png", publicUrl: "https://cdn.example/logo.png" }],
    });
    expect(lastOf(posted.messages)?.message).toBe("Show my reviews\n\n[Attached file: logo.png — https://cdn.example/logo.png]");
    expect(lastOf(client.readStoredMessages(1))).toMatchObject({ id: 108, role: "ASSISTANT", message: "First answer" });

    client.emit(1);
    expect(lastOf(receivedSignals)).toEqual({ changeCounter: 1, lastMessageId: 108, runStatus: "idle", summaryUpdatedAt: null });

    unsubscribe();
    client.emit(1, { runStatus: "running" });
    expect(receivedSignals).toHaveLength(2);
  });

  it("starts a new conversation and reopens the conversation of a known entry point", async () => {
    const client = buildClient();
    const started = await client.startConversation({ agentSpaceKey: 7, text: "", startSignalText: "Owner clicked the briefing" });
    expect(started.reopenedExisting).toBe(false);
    expect(started.conversation.id).toBe(4);
    expect(started.messages.map((message) => message.messageType)).toEqual(["SIGNAL"]);
    expect(lastOf(client.readStoredMessages(4))?.message).toBe("First answer");

    const reopened = await client.startConversation({ agentSpaceKey: 7, text: "Hello", entryPointKey: "hub.starter.R2" });
    expect(reopened).toMatchObject({ reopenedExisting: true, messages: [], conversation: { id: 1 } });
  });

  it("refuses input into a closed conversation and a retry with nothing to retry", async () => {
    const client = buildClient();
    client.setRunState(1, "COMPLETED");
    await expect(client.postMessage(1, { text: "Hi" })).rejects.toMatchObject({ kind: "closed", code: "CONVERSATION_CLOSED" });
    await expect(client.injectSignal(1, { text: "Clicked" })).rejects.toBeInstanceOf(AgentsClientError);
    await expect(client.retryConversation(1)).rejects.toMatchObject({ kind: "other", httpStatus: 400 });
    await expect(client.getConversationMetadata(99)).rejects.toMatchObject({ kind: "notFound" });
  });

  it("searches messages, renames, deletes and ends subscriptions", async () => {
    const client = buildClient();
    expect((await client.searchConversationMessages(1, "REVIEWS 3")).map((hit) => hit.messageId)).toEqual([104]);
    expect((await client.searchConversations("reviews"))[0]?.hits).toHaveLength(6);
    expect(await client.searchConversationMessages(1, "reviews", { limit: 2 })).toHaveLength(2);
    expect((await client.renameConversation(1, "Reviews")).title).toBe("Reviews");

    const onEnd = vi.fn();
    subscribe(client, 1, () => undefined, onEnd);
    client.end(1, "gone");
    expect(onEnd).toHaveBeenCalledWith("gone");

    await client.deleteConversation(1);
    await expect(client.getConversationMetadata(1)).rejects.toMatchObject({ kind: "notFound" });
    expect(client.getSelectableModels).toBeUndefined();
    expect(client.getMessageSentPayload).toBeUndefined();
  });

  it("answers the optional functions only when their data is handed in", async () => {
    const client = createInMemoryAgentsConversationClient({
      conversations: [{ conversation: buildConversation({ id: 1 }), messages: [buildMessage(101, 1, 0, "USER", "Hi")] }],
      selectableModels: [{ name: "model-a", displayName: "Model A", description: "", strengthLevel: 3, speedLevel: 2 }],
      sentPayloadsByMessageId: { 101: [{ role: "user", content: "Hi" }] },
    });
    expect(await client.getSelectableModels?.()).toEqual([
      { name: "model-a", displayName: "Model A", description: "", strengthLevel: 3, speedLevel: 2 },
    ]);
    expect(await client.getMessageSentPayload?.(1, 101)).toEqual([{ role: "user", content: "Hi" }]);
  });
});

describe("a client without push (the polling default's counterpart)", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("stores the answer after the delay, RUNNING meanwhile, and reports the newest message id in the metadata", async () => {
    vi.useFakeTimers();
    const client = createInMemoryAgentsConversationClient({
      conversations: [{ conversation: buildConversation({ id: 1 }), messages: [buildMessage(101, 1, 0, "USER", "Hi")] }],
      scriptedAssistantAnswers: ["Later answer"],
      assistantAnswerDelayMs: 1500,
      withoutChangeSubscription: true,
    });
    expect(client.subscribeToConversationChanges).toBeUndefined();
    expect(await client.getConversationMetadata(1)).toMatchObject({ runState: "IDLE", lastMessageId: 101 });

    const posted = await client.postMessage(1, { text: "Show my reviews" });
    expect(lastOf(posted.messages)).toMatchObject({ id: 102, message: "Show my reviews" });
    expect(await client.getConversationMetadata(1)).toMatchObject({ runState: "RUNNING", lastMessageId: 102 });

    await vi.advanceTimersByTimeAsync(1499);
    expect(lastOf(client.readStoredMessages(1))?.message).toBe("Show my reviews");
    await vi.advanceTimersByTimeAsync(1);
    expect(lastOf(client.readStoredMessages(1))).toMatchObject({ id: 103, role: "ASSISTANT", message: "Later answer" });
    expect(await client.getConversationMetadata(1)).toMatchObject({ runState: "IDLE", lastMessageId: 103 });
  });

  it("counts the requests it answered, per port function and in all", async () => {
    const client = buildClient();
    await client.getConversationMetadata(1);
    await client.getConversationMetadata(1);
    await client.getChangedMessages(1, { highestKnownId: 106, fromIndex: 5 });
    expect(client.readRequestCount("getConversationMetadata")).toBe(2);
    expect(client.readRequestCount("getChangedMessages")).toBe(1);
    expect(client.readRequestCount("postMessage")).toBe(0);
    expect(client.readRequestCount()).toBe(3);
  });
});
