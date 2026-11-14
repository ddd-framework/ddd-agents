import { type Dispatch, type RefObject, type SetStateAction, useCallback, useEffect, useRef, useState } from "react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import type { ConversationRunStatus } from "../../contract/conversation";
import type { AgentsChatStore } from "../store/create-agents-chat-store";
import { ConversationChangeSignalRules, type ConversationLiveUpdateReads } from "./conversation-change-signal-rules";
import type { ConversationMessagePages } from "./use-conversation-message-pages";

/** The chat's reads behind the live-update rules: the changed tail and the metadata of the chat store's conversation. */
class ChatStoreLiveUpdateReads implements ConversationLiveUpdateReads {
  constructor(
    protected readonly chatStore: AgentsChatStore,
    /** The latest reads of the chat (a ref: their object changes with the older-page load state, the subscription does not). */
    protected readonly messagePagesRef: RefObject<ConversationMessagePages>,
    protected readonly conversationId: number,
    protected readonly setLiveReadCount: Dispatch<SetStateAction<number>>,
    readonly showRunStatus: Dispatch<SetStateAction<ConversationRunStatus | null>>,
  ) {}

  readChangedMessages = (): void => {
    this.setLiveReadCount((count) => count + 1);
    this.messagePagesRef.current.refetchChangedTail(this.conversationId);
  };

  readConversationMetadata = (): void => {
    void this.messagePagesRef.current.readConversationMetadata(this.conversationId);
  };

  hasLoadedMessage = (messageId: number): boolean =>
    (this.chatStore.getState().messages ?? []).some((message) => message.id === messageId);
}

/** The run status alone, for code outside the chat: no reads behind the rules. */
class RunStatusOnlyLiveUpdateReads implements ConversationLiveUpdateReads {
  constructor(readonly showRunStatus: Dispatch<SetStateAction<ConversationRunStatus | null>>) {}

  readChangedMessages = (): void => undefined;

  readConversationMetadata = (): void => undefined;

  hasLoadedMessage = (): boolean => true;
}

export interface ConversationLiveUpdatesInputs {
  client: AgentsConversationClient;
  chatStore: AgentsChatStore;
  /** The reads the rules trigger (`useConversationMessagePages`). */
  messagePages: ConversationMessagePages;
  /** The conversation to follow; undefined for a cold chat (no subscription). */
  conversationId: number | undefined;
}

export interface ConversationLiveUpdates {
  /** The run status the signal (or the binding's run-state check) reported last; null before the first and once ended. */
  runStatus: ConversationRunStatus | null;
  /** Counts the live reads the rules triggered — the activity read follows it (one activity read per signal tick). */
  liveReadCount: number;
  /** Reads the changed tail now, bypassing the throttle, and counts it (the chat's safety poll). */
  readChangedTailNow: () => void;
}

/**
 * The chat's live updates over `subscribeToConversationChanges`: one subscription per conversation,
 * the rules of `ConversationChangeSignalRules`, the reads written into the chat store.
 */
export const useConversationLiveUpdates = (inputs: ConversationLiveUpdatesInputs): ConversationLiveUpdates => {
  const { client, chatStore, messagePages, conversationId } = inputs;
  const [runStatus, setRunStatus] = useState<ConversationRunStatus | null>(null);
  const [liveReadCount, setLiveReadCount] = useState(0);
  const messagePagesRef = useRef(messagePages);
  messagePagesRef.current = messagePages;

  useEffect(() => {
    // A client without a subscription follows nothing here; `AgentChat` fills in the polling default before.
    if (!conversationId || !client.subscribeToConversationChanges) return;
    const rules = new ConversationChangeSignalRules(
      new ChatStoreLiveUpdateReads(chatStore, messagePagesRef, conversationId, setLiveReadCount, setRunStatus),
    );
    const unsubscribe = client.subscribeToConversationChanges(conversationId, rules.receiveSignal, rules.receiveEnd);
    return () => {
      rules.dispose();
      unsubscribe();
    };
  }, [client, chatStore, conversationId]);

  const readChangedTailNow = useCallback(() => {
    if (!conversationId) return;
    setLiveReadCount((count) => count + 1);
    messagePagesRef.current.refetchChangedTail(conversationId);
  }, [conversationId]);

  return { runStatus, liveReadCount, readChangedTailNow };
};

/**
 * The run status of a conversation for code outside the chat (the launcher button, the end of a voice call), over the
 * same port function and the same status rules as the chat — without reading any message.
 */
export const useAgentsConversationRunStatus = (
  client: AgentsConversationClient,
  conversationId: number | undefined,
): ConversationRunStatus | null => {
  const [runStatus, setRunStatus] = useState<ConversationRunStatus | null>(null);
  useEffect(() => {
    // A client without a subscription reports nothing; wrap it in `withPollingChangeSubscription` first.
    if (!conversationId || !client.subscribeToConversationChanges) return;
    const rules = new ConversationChangeSignalRules(new RunStatusOnlyLiveUpdateReads(setRunStatus));
    const unsubscribe = client.subscribeToConversationChanges(conversationId, rules.receiveSignal, rules.receiveEnd);
    return () => {
      rules.dispose();
      unsubscribe();
    };
  }, [client, conversationId]);
  return runStatus;
};
