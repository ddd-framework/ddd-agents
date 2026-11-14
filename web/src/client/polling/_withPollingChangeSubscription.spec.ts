import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import type { AgentsConversationMessage, ConversationChangeSignal } from "../../contract/conversation";
import { createInMemoryAgentsConversationClient } from "../testing/createInMemoryAgentsConversationClient";
import { withPollingChangeSubscription } from "./withPollingChangeSubscription";

/**
 * `withPollingChangeSubscription`: a client with its own subscription passes unchanged; a client
 * without one gets the polling default, every port function still reaches the binding, and a send the server accepted
 * reads the metadata at once.
 */

const buildMessage = (id: number, indexInConversation: number, role: "USER" | "ASSISTANT"): AgentsConversationMessage => ({
  id,
  conversationId: 1,
  indexInConversation,
  role,
  messageType: "TEXT",
  status: "COMPLETED",
  authorType: role,
  signalSource: null,
  message: `Message ${id}`,
  createdDate: null,
  toolCall: null,
  delegatedConversations: [],
  subAgent: null,
});

const buildClient = (withoutChangeSubscription: boolean) =>
  createInMemoryAgentsConversationClient({
    conversations: [
      {
        conversation: {
          id: 1,
          agentSpaceKey: 7,
          runState: "IDLE",
          agentTurnOwedAt: null,
          title: "Visits",
          titleManuallyEdited: false,
          summary: null,
          created: null,
          updated: null,
          purposeName: null,
          initiatorType: null,
          scheduleId: null,
          entryPointKey: null,
          progressSteps: [],
        },
        messages: [buildMessage(11, 0, "USER"), buildMessage(12, 1, "ASSISTANT")],
      },
    ],
    scriptedAssistantAnswers: ["The answer."],
    assistantAnswerDelayMs: 3_000,
    selectableModels: [{ name: "model-a", displayName: "Model A", description: "", strengthLevel: 1, speedLevel: 1 }],
    withoutChangeSubscription,
  });

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("withPollingChangeSubscription", () => {
  test("returns a client that brings its own subscription unchanged", () => {
    const client = buildClient(false);
    expect(withPollingChangeSubscription(client)).toBe(client);
  });

  test("polls a client without push: a send reads at once, the delayed answer arrives as an idle signal", async () => {
    const binding = buildClient(true);
    const client = withPollingChangeSubscription(binding, { documentVisibility: null });
    expect(client).not.toBe(binding);
    expect(await client.getSelectableModels?.()).toHaveLength(1);
    expect(client.getMessageSentPayload).toBeUndefined();

    const signals: ConversationChangeSignal[] = [];
    const unsubscribe = client.subscribeToConversationChanges(1, (signal) => signals.push(signal), vi.fn());
    await vi.advanceTimersByTimeAsync(0);
    expect(signals).toEqual([{ changeCounter: 0, lastMessageId: 12, runStatus: "idle", summaryUpdatedAt: 0 }]);

    await client.postMessage(1, { text: "Show my reviews" });
    await vi.advanceTimersByTimeAsync(0);
    expect(binding.readRequestCount("getConversationMetadata")).toBe(2);
    expect(signals[1]).toEqual({ changeCounter: 1, lastMessageId: 13, runStatus: "running", summaryUpdatedAt: 0 });

    // The answer is stored at 3 s; the 2 s polls see it at 4 s.
    await vi.advanceTimersByTimeAsync(4_000);
    expect(signals[signals.length - 1]).toEqual({ changeCounter: 2, lastMessageId: 14, runStatus: "idle", summaryUpdatedAt: 0 });

    const metadataReadsBeforeUnsubscribe = binding.readRequestCount("getConversationMetadata");
    unsubscribe();
    await vi.advanceTimersByTimeAsync(120_000);
    expect(binding.readRequestCount("getConversationMetadata")).toBe(metadataReadsBeforeUnsubscribe);
  });

  test("a signal and a retry the server accepted read at once too", async () => {
    const binding = buildClient(true);
    const client = withPollingChangeSubscription(binding, { documentVisibility: null });
    client.subscribeToConversationChanges(1, vi.fn(), vi.fn());
    await vi.advanceTimersByTimeAsync(0);
    await client.injectSignal(1, { text: "Clicked" });
    await vi.advanceTimersByTimeAsync(0);
    expect(binding.readRequestCount("getConversationMetadata")).toBe(2);
    await vi.advanceTimersByTimeAsync(3_000);
    await expect(client.retryConversation(1)).rejects.toMatchObject({ httpStatus: 400 });
    expect(binding.readRequestCount("retryConversation")).toBe(1);
  });
});
