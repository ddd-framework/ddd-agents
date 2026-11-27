// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { createRef } from "react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { useAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import { createInMemoryAgentsConversationClient } from "../../client/testing/createInMemoryAgentsConversationClient";
import { AgentsProvider } from "../../provider/AgentsProvider";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { AgentChat } from "./AgentChat";
/**
 * What a host window around `AgentChat` sets: the client as a prop (its hooks and slots reach it), a draft put into the
 * mounted composer, the split view switched off, the package's own background, translated texts — and the composer
 * keeps its placeholder once a conversation exists, focused or not.
 */
const buildMessage = (fields) => ({
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
const buildClient = () => createInMemoryAgentsConversationClient({
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
                buildMessage({ id: 12, indexInConversation: 1, role: "ASSISTANT", message: "They grew." }),
            ],
        },
    ],
});
const ClientProbe = () => {
    const client = useAgentsConversationClient();
    return <span data-testid="client-probe">{typeof client.subscribeToConversationChanges}</span>;
};
describe("AgentChat host options", () => {
    let appEnvironment;
    beforeEach(() => {
        appEnvironment = getAgentsEnvironment();
        setAgentsEnvironment({});
        Element.prototype.scrollIntoView ??= vi.fn();
    });
    afterEach(() => {
        cleanup();
        setAgentsEnvironment(appEnvironment);
    });
    test("a client given as a prop reaches the chat's hooks and the host's children, with its live updates", async () => {
        render(<AgentChat client={buildClient()} conversationId={1}>
        <ClientProbe />
      </AgentChat>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("client-probe")).toHaveTextContent("function");
    });
    test("composerRef puts a draft into the mounted composer without sending it", async () => {
        const client = buildClient();
        const composerRef = createRef();
        render(<AgentChat client={client} conversationId={1} composerRef={composerRef}/>);
        await screen.findByText("They grew.");
        act(() => composerRef.current?.setComposerDraft("Compare with last month"));
        expect(screen.getByTestId("chat-input-textarea")).toHaveTextContent("Compare with last month");
        expect(client.readStoredMessages(1)).toHaveLength(2);
    });
    test("the composer keeps its placeholder in an open conversation, also while focused", async () => {
        render(<AgentChat client={buildClient()} conversationId={1}/>);
        await screen.findByText("They grew.");
        const composer = screen.getByTestId("chat-input-textarea");
        act(() => {
            fireEvent.focus(composer);
        });
        expect(composer).toHaveAttribute("data-placeholder", "Ask anything");
        expect(composer.className).toMatch(/showPlaceholder/);
    });
    test("the composer's texts go through the host's translate function", async () => {
        setAgentsEnvironment({ texts: { translate: (key) => `[de] ${key}` } });
        render(<AgentChat client={buildClient()} conversationId={1}/>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("chat-input-textarea")).toHaveAttribute("data-placeholder", "[de] Ask anything");
    });
    test("the icon-only buttons beside the composer carry accessible names from the package's texts", async () => {
        setAgentsEnvironment({ texts: { translate: (key) => `[de] ${key}` } });
        render(<AgentChat client={buildClient()} conversationId={1}/>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("chat-input-send-button")).toHaveAttribute("aria-label", "[de] Send message");
        expect(screen.getByRole("button", { name: "[de] Send message" })).toBe(screen.getByTestId("chat-input-send-button"));
        // the round scroll-to-latest button the chat renders beside the composer
        expect(screen.getByTestId("chat-scroll-to-latest-button")).toHaveAttribute("aria-label", "[de] Scroll to the latest message");
    });
    test("the composer reads the texts of an AgentsProvider subtree", async () => {
        render(<AgentsProvider environment={{ texts: { translate: (key) => `[fr] ${key}` } }}>
        <AgentChat client={buildClient()} conversationId={1}/>
      </AgentsProvider>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("chat-input-textarea")).toHaveAttribute("data-placeholder", "[fr] Ask anything");
    });
    test("disableSplitView renders the message column without the split, backgroundPaintedByHost=false paints the pane", async () => {
        const { container, rerender } = render(<AgentChat client={buildClient()} conversationId={1}/>);
        await screen.findByText("They grew.");
        expect(container.querySelector(".agents-split-view")).not.toBeNull();
        expect(container.querySelector("[class*='backgroundPaintedByHost']")).not.toBeNull();
        rerender(<AgentChat client={buildClient()} conversationId={1} disableSplitView backgroundPaintedByHost={false}/>);
        await screen.findByText("They grew.");
        expect(container.querySelector(".agents-split-view")).toBeNull();
        expect(container.querySelector("[class*='backgroundPaintedByHost']")).toBeNull();
    });
});
