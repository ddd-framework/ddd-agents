// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { createRef } from "react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { createInMemoryAgentsConversationClient } from "../../client/testing/createInMemoryAgentsConversationClient";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { AgentChat } from "./AgentChat";
/**
 * The host slots of `AgentChat` an avatar host builds on: the avatar beside each assistant message, the resting
 * indicator mounted between turns with the chat store and the open widget question, `onMessageSent` for a send
 * animation, and host icons in the composer's action row that keep their own fill.
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
const receivedActivityProps = [];
const RecordingIndicator = (props) => {
    receivedActivityProps.push(props);
    return <span data-testid="resting-indicator">{props.active ? "working" : "resting"}</span>;
};
describe("AgentChat host slots", () => {
    let appEnvironment;
    beforeEach(() => {
        appEnvironment = getAgentsEnvironment();
        setAgentsEnvironment({});
        Element.prototype.scrollIntoView ??= vi.fn();
        receivedActivityProps.length = 0;
    });
    afterEach(() => {
        cleanup();
        setAgentsEnvironment(appEnvironment);
    });
    test("AssistantAvatar stands beside each assistant message and beside no owner message", async () => {
        render(<AgentChat client={buildClient()} conversationId={1} AssistantAvatar={({ message }) => <span data-testid="assistant-avatar">{String(message.id)}</span>}/>);
        await screen.findByText("They grew.");
        expect(screen.getAllByTestId("assistant-avatar").map((avatar) => avatar.textContent)).toEqual(["12"]);
    });
    test("the indicator is mounted while the chat rests and gets the chat store and the open widget question", async () => {
        render(<AgentChat client={buildClient()} conversationId={1} ThinkingIndicator={RecordingIndicator}/>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("resting-indicator")).toHaveTextContent("resting");
        const latestActivityProps = receivedActivityProps[receivedActivityProps.length - 1];
        expect(typeof latestActivityProps.chatStore.getState).toBe("function");
        expect(latestActivityProps.unansweredWidgetMessageId).toBeNull();
    });
    test("onMessageSent reports an accepted send with its text", async () => {
        const onMessageSent = vi.fn();
        const composerRef = createRef();
        render(<AgentChat client={buildClient()} conversationId={1} composerRef={composerRef} onMessageSent={onMessageSent}/>);
        await screen.findByText("They grew.");
        act(() => composerRef.current?.setComposerDraft("And last week?"));
        act(() => {
            fireEvent.click(screen.getByTestId("chat-input-send-button"));
        });
        expect(onMessageSent).toHaveBeenCalledTimes(1);
        expect(onMessageSent).toHaveBeenCalledWith("And last week?");
    });
    test("a host control in the action row sits outside the package's own icon fill", async () => {
        render(<AgentChat client={buildClient()} conversationId={1} inputActionsSlot={<button data-testid="host-gear" type="button"/>}/>);
        await screen.findByText("They grew.");
        expect(screen.getByTestId("host-gear").closest('[class*="ownAction"]')).toBeNull();
        expect(screen.getByTestId("chat-input-send-button").closest('[class*="ownAction"]')).not.toBeNull();
    });
});
