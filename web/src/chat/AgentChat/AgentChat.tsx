import { type ComponentType, type RefObject, useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import { useOptionalAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import type { PollingConversationChangeSubscriptionOptions } from "../../client/polling/createPollingConversationChangeSubscription";
import { withPollingChangeSubscription } from "../../client/polling/withPollingChangeSubscription";
import { useAgentsActiveAgentSpaceKey } from "../../provider/AgentsProvider";
import { AgentChatActivityHistory } from "../AgentChatActivityHistory/AgentChatActivityHistory";
import { AgentChatActivityIndicator } from "../AgentChatActivityIndicator/AgentChatActivityIndicator";
import { ConversationClosedBar } from "../ConversationClosedBar/ConversationClosedBar";
import {
  type AgentChatActivityHistoryProps,
  type AgentChatActivityProps,
  AgentChatController,
  type AgentChatControllerProps,
} from "../controller/AgentChatController";
import type { AgentsChatState } from "../store/agents-chat-state";
import { type AgentsChatStore, createAgentsChatStore } from "../store/create-agents-chat-store";
import { useAgentsChatSelector } from "../store/use-agents-chat-store";
import styles from "./AgentChat.module.scss";

/** The controller's inputs that `AgentChat` fills itself when the app leaves them out. */
type DefaultedControllerProps = "chatStore" | "client" | "agentSpaceKey" | "chatScrollRef" | "ThinkingIndicator";

export interface AgentChatProps extends Omit<AgentChatControllerProps, DefaultedControllerProps> {
  /** This chat instance's state handle. Default: a store of its own, created on mount and dropped on unmount. */
  chatStore?: AgentsChatStore;
  /**
   * The conversation client. Default: the one `AgentsProvider` (or `AgentsConversationClientProvider`) mounted. A client
   * without `subscribeToConversationChanges` gets the polling default (`withPollingChangeSubscription`).
   */
  client?: AgentsConversationClient;
  /**
   * The intervals of the polling default, for a client without a subscription of its own. Read once per client (when
   * the chat wraps it); ignored for a client that brings its own subscription.
   */
  pollingOptions?: PollingConversationChangeSubscriptionOptions;
  /** The key of the agent space new conversations start in. Default: `AgentsEnvironment.useActiveAgentSpaceKey()`, else 0 (no agent space). */
  agentSpaceKey?: number;
  /** The scroll container of the message list, when the app needs it (a scroll-linked background). Default: internal. */
  chatScrollRef?: RefObject<HTMLDivElement | null>;
  /** The agent's activity at the tail of the thread. Default: `AgentChatActivityIndicator` (dots and the ticker line). */
  ThinkingIndicator?: ComponentType<AgentChatActivityProps>;
}

const selectConversation = (state: AgentsChatState) => state.conversation;

/**
 * The agent chat with the package defaults (`./chat`): `<AgentsProvider client={client}><AgentChat
 * /></AgentsProvider>` is a working chat — message list, composer, activity indicator, error card with retry, canvas
 * pane, quick replies, older pages on scroll-up, the closed bar, live updates over the client's own subscription or,
 * without one, the polling default. Every slot and input of `AgentChatController` can still
 * be passed; what is left out gets its default here. Without `conversationId` the chat starts empty, lazy-starts a
 * conversation on the first send and then follows that conversation by itself (`onNewConversation` reports it).
 */
export const AgentChat = (props: AgentChatProps) => {
  const {
    chatStore: appChatStore,
    client: appClient,
    pollingOptions,
    agentSpaceKey: appAgentSpaceKey,
    chatScrollRef: appChatScrollRef,
    ThinkingIndicator = AgentChatActivityIndicator,
    ActivityHistory = AgentChatActivityHistory,
    conversationId: appConversationId,
    onNewConversation,
    inputReplacementSlot,
    chatAreaClassName = styles.chatArea,
    chatWrapClassName = styles.chatWrap,
    ...controllerProps
  } = props;

  const mountedClient = useOptionalAgentsConversationClient();
  const givenClient = appClient ?? mountedClient;
  // Live updates for a client without push: the polling default, one wrapper per given client. A client with its
  // own subscription is passed through unchanged.
  const client = useMemo(
    () => (givenClient === null ? null : withPollingChangeSubscription(givenClient, pollingOptions)),
    // The options are read once per client: an inline object must not re-create the subscription on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [givenClient],
  );
  const [ownChatStore] = useState(createAgentsChatStore);
  const chatStore = appChatStore ?? ownChatStore;
  const environmentAgentSpaceKey = useAgentsActiveAgentSpaceKey();
  const agentSpaceKey = appAgentSpaceKey ?? environmentAgentSpaceKey ?? 0;
  const ownChatScrollRef = useRef<HTMLDivElement | null>(null);
  const chatScrollRef = appChatScrollRef ?? ownChatScrollRef;

  // The conversation a lazy start opened, followed until the app names another one.
  const [startedConversationId, setStartedConversationId] = useState<number | undefined>(undefined);
  useEffect(() => {
    setStartedConversationId(undefined);
  }, [appConversationId]);
  const conversationId = appConversationId ?? startedConversationId;
  const handleNewConversation = useCallback(
    (newConversationId: number) => {
      setStartedConversationId(newConversationId);
      onNewConversation?.(newConversationId);
    },
    [onNewConversation],
  );

  // A terminal run state: the server takes no further input, the closed bar stands in the composer's place.
  const conversation = useAgentsChatSelector(chatStore, selectConversation);
  const isConversationRunEnded = conversation?.runState === "COMPLETED" || conversation?.runState === "CANCELLED";

  if (client === null) {
    throw new Error("AgentChat has no conversation client: pass `client`, or mount it in <AgentsProvider client={…}>.");
  }
  return (
    <AgentChatController
      {...controllerProps}
      chatStore={chatStore}
      client={client}
      agentSpaceKey={agentSpaceKey}
      conversationId={conversationId}
      onNewConversation={handleNewConversation}
      chatScrollRef={chatScrollRef}
      ThinkingIndicator={ThinkingIndicator}
      ActivityHistory={ActivityHistory}
      inputReplacementSlot={inputReplacementSlot ?? (isConversationRunEnded ? <ConversationClosedBar /> : undefined)}
      chatAreaClassName={chatAreaClassName}
      chatWrapClassName={chatWrapClassName}
    />
  );
};

export type { AgentChatActivityHistoryProps, AgentChatActivityProps };
