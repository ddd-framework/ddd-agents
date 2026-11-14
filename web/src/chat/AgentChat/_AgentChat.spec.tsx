// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import {
  createInMemoryAgentsConversationClient,
  type InMemoryAgentsConversationClientData,
} from "../../client/testing/createInMemoryAgentsConversationClient";
import type { AgentsConversationMessage } from "../../contract/conversation";
import { AgentsProvider } from "../../provider/AgentsProvider";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { AgentChat } from "./AgentChat";

/**
 * The default chat without an app: no environment member, no Redux store, no app registration — the
 * in-memory client over one recorded conversation. The recorded messages render, a quick reply is sent as the owner's
 * message, and the scripted answer arrives only through the client's change signal.
 */

const QUICK_REPLIES_FENCE = [
  "```widget",
  JSON.stringify({ type: "QuickReplies", question: "Next?", options: ["Show the top keywords", "Compare months"] }),
  "```",
].join("\n");

const buildMessage = (
  fields: Pick<AgentsConversationMessage, "id" | "indexInConversation" | "role" | "message">,
): AgentsConversationMessage => ({
  conversationId: 1,
  messageType: "TEXT",
  status: "COMPLETED",
  authorType: fields.role === "USER" ? "USER" : "ASSISTANT",
  signalSource: null,
  createdDate: null,
  toolCall: null,
  delegatedConversations: [],
  subAgent: null,
  ...fields,
});

const buildClient = (clientOptions: Partial<InMemoryAgentsConversationClientData> = {}) =>
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
        messages: [
          buildMessage({ id: 11, indexInConversation: 0, role: "USER", message: "How were my visits?" }),
          buildMessage({
            id: 12,
            indexInConversation: 1,
            role: "ASSISTANT",
            message: `Your **search visits** grew last week.\n\n${QUICK_REPLIES_FENCE}`,
          }),
        ],
      },
    ],
    scriptedAssistantAnswers: ["The top keyword is dentist kassel."],
    ...clientOptions,
  });

describe("AgentChat with the package defaults", () => {
  let appEnvironment: AgentsEnvironment;

  beforeEach(() => {
    appEnvironment = getAgentsEnvironment();
    setAgentsEnvironment({});
    // jsdom lays nothing out: the view's bottom follow only needs the call to exist.
    Element.prototype.scrollIntoView ??= vi.fn();
  });

  afterEach(() => {
    // Unmount first: the app's environment would re-render the chat with hooks that need the app's own store.
    cleanup();
    setAgentsEnvironment(appEnvironment);
  });

  test("renders the recorded conversation, sends a quick reply and shows the answer the change signal brings", async () => {
    const client = buildClient();
    render(
      <AgentsProvider client={client}>
        <AgentChat conversationId={1} agentSpaceKey={7} />
      </AgentsProvider>,
    );

    await screen.findByText("How were my visits?");
    await screen.findByText("search visits");
    const quickReply = await screen.findByTestId("quick-replies-widget-option-Show the top keywords");

    await act(async () => {
      fireEvent.click(quickReply);
    });
    await waitFor(() =>
      expect(screen.getAllByTestId("chat-message-user").map((bubble) => bubble.textContent)).toContain(
        "Show the top keywords",
      ),
    );
    expect(client.readStoredMessages(1).map((message) => message.message)).toContain("Show the top keywords");
    expect(screen.queryByText("The top keyword is dentist kassel.")).toBeNull();

    await act(async () => {
      client.emit(1);
    });
    await screen.findByText("The top keyword is dentist kassel.");
  });

  test("follows a client without a subscription through the polling default: the delayed answer arrives by itself", async () => {
    const client = buildClient({ assistantAnswerDelayMs: 150, withoutChangeSubscription: true });
    render(
      <AgentsProvider client={client}>
        <AgentChat conversationId={1} agentSpaceKey={7} pollingOptions={{ runningIntervalMs: 50, idleIntervalMs: 100 }} />
      </AgentsProvider>,
    );

    const quickReply = await screen.findByTestId("quick-replies-widget-option-Show the top keywords");
    await act(async () => {
      fireEvent.click(quickReply);
    });
    await waitFor(() =>
      expect(screen.getAllByTestId("chat-message-user").map((bubble) => bubble.textContent)).toContain(
        "Show the top keywords",
      ),
    );
    expect(screen.queryByText("The top keyword is dentist kassel.")).toBeNull();
    await screen.findByText("The top keyword is dentist kassel.", undefined, { timeout: 3000 });

    // After the unmount no timer of the chat reads any more.
    cleanup();
    const requestCountAfterUnmount = client.readRequestCount();
    await new Promise((resolveWait) => setTimeout(resolveWait, 400));
    expect(client.readRequestCount()).toBe(requestCountAfterUnmount);
  });

  test("shows the closed bar in place of the composer on a completed conversation", async () => {
    const client = buildClient();
    client.setRunState(1, "COMPLETED");
    render(
      <AgentsProvider client={client}>
        <AgentChat conversationId={1} agentSpaceKey={7} />
      </AgentsProvider>,
    );

    await screen.findByTestId("agent-chat-closed-bar");
    expect(screen.queryByTestId("chat-input-textarea")).toBeNull();
  });

  test("throws a plain error without a conversation client", () => {
    const consoleError = console.error;
    console.error = () => undefined;
    try {
      expect(() => render(<AgentChat />)).toThrow(/no conversation client/);
    } finally {
      console.error = consoleError;
    }
  });
});
