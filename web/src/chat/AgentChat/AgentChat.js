import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AgentsConversationClientProvider, useOptionalAgentsConversationClient, } from "../../client/AgentsConversationClientContext";
import { withPollingChangeSubscription } from "../../client/polling/withPollingChangeSubscription";
import { useAgentsActiveAgentSpaceKey } from "../../provider/AgentsProvider";
import { classNames } from "../../provider/class-names";
import { AgentChatActivityHistory } from "../AgentChatActivityHistory/AgentChatActivityHistory";
import { AgentChatActivityIndicator } from "../AgentChatActivityIndicator/AgentChatActivityIndicator";
import { ConversationClosedBar } from "../ConversationClosedBar/ConversationClosedBar";
import { AgentChatController, } from "../controller/AgentChatController";
import { createAgentsChatStore } from "../store/create-agents-chat-store";
import { useAgentsChatSelector } from "../store/use-agents-chat-store";
import styles from "./AgentChat.module.scss";
const selectConversation = (state) => state.conversation;
/**
 * The agent chat with the package defaults (`./chat`): `<AgentsProvider client={client}><AgentChat
 * /></AgentsProvider>` is a working chat — message list, composer, activity indicator, error card with retry, canvas
 * pane, quick replies, older pages on scroll-up, the closed bar, live updates over the client's own subscription or,
 * without one, the polling default. Every slot and input of `AgentChatController` can still
 * be passed; what is left out gets its default here. Without `conversationId` the chat starts empty, lazy-starts a
 * conversation on the first send and then follows that conversation by itself (`onNewConversation` reports it).
 */
export const AgentChat = (props) => {
    const { chatStore: appChatStore, client: appClient, pollingOptions, agentSpaceKey: appAgentSpaceKey, chatScrollRef: appChatScrollRef, ThinkingIndicator = AgentChatActivityIndicator, ActivityHistory = AgentChatActivityHistory, conversationId: appConversationId, onNewConversation, inputReplacementSlot, chatAreaClassName, chatWrapClassName = styles.chatWrap, showsTopBlurBand = false, confineTopBlurBandToChatPane = showsTopBlurBand, ...controllerProps } = props;
    const mountedClient = useOptionalAgentsConversationClient();
    const givenClient = appClient ?? mountedClient;
    // Live updates for a client without push: the polling default, one wrapper per given client. A client with its
    // own subscription is passed through unchanged.
    const client = useMemo(() => (givenClient === null ? null : withPollingChangeSubscription(givenClient, pollingOptions)), 
    // The options are read once per client: an inline object must not re-create the subscription on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [givenClient]);
    const [ownChatStore] = useState(createAgentsChatStore);
    const chatStore = appChatStore ?? ownChatStore;
    const environmentAgentSpaceKey = useAgentsActiveAgentSpaceKey();
    const agentSpaceKey = appAgentSpaceKey ?? environmentAgentSpaceKey ?? 0;
    const ownChatScrollRef = useRef(null);
    const chatScrollRef = appChatScrollRef ?? ownChatScrollRef;
    // The conversation a lazy start opened, followed until the app names another one.
    const [startedConversationId, setStartedConversationId] = useState(undefined);
    useEffect(() => {
        setStartedConversationId(undefined);
    }, [appConversationId]);
    const conversationId = appConversationId ?? startedConversationId;
    const handleNewConversation = useCallback((newConversationId) => {
        setStartedConversationId(newConversationId);
        onNewConversation?.(newConversationId);
    }, [onNewConversation]);
    // A terminal run state: the server takes no further input, the closed bar stands in the composer's place.
    const conversation = useAgentsChatSelector(chatStore, selectConversation);
    const isConversationRunEnded = conversation?.runState === "COMPLETED" || conversation?.runState === "CANCELLED";
    if (client === null) {
        throw new Error("AgentChat has no conversation client: pass `client`, or mount it in <AgentsProvider client={…}>.");
    }
    // The chat's client (with its live updates) reaches every hook below — the package's and the host's slots
    // (`useAgentsConversationClient`), also when it came as the `client` prop and not from a provider.
    return (<AgentsConversationClientProvider client={client}>
      <AgentChatController {...controllerProps} confineTopBlurBandToChatPane={confineTopBlurBandToChatPane} chatStore={chatStore} client={client} agentSpaceKey={agentSpaceKey} conversationId={conversationId} onNewConversation={handleNewConversation} chatScrollRef={chatScrollRef} ThinkingIndicator={ThinkingIndicator} ActivityHistory={ActivityHistory} inputReplacementSlot={inputReplacementSlot ?? (isConversationRunEnded ? <ConversationClosedBar /> : undefined)} chatAreaClassName={chatAreaClassName ?? classNames(styles.chatArea, showsTopBlurBand ? styles.chatAreaWithTopBlurBand : undefined)} chatWrapClassName={chatWrapClassName}/>
    </AgentsConversationClientProvider>);
};
