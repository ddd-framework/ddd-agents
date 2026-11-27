import { useCallback, useEffect, useRef, useState } from "react";
import { ConversationChangeSignalRules } from "./conversation-change-signal-rules";
/** The chat's reads behind the live-update rules: the changed tail and the metadata of the chat store's conversation. */
class ChatStoreLiveUpdateReads {
    chatStore;
    messagePagesRef;
    conversationId;
    setLiveReadCount;
    showRunStatus;
    constructor(chatStore, 
    /** The latest reads of the chat (a ref: their object changes with the older-page load state, the subscription does not). */
    messagePagesRef, conversationId, setLiveReadCount, showRunStatus) {
        this.chatStore = chatStore;
        this.messagePagesRef = messagePagesRef;
        this.conversationId = conversationId;
        this.setLiveReadCount = setLiveReadCount;
        this.showRunStatus = showRunStatus;
    }
    readChangedMessages = () => {
        this.setLiveReadCount((count) => count + 1);
        this.messagePagesRef.current.refetchChangedTail(this.conversationId);
    };
    readConversationMetadata = () => {
        void this.messagePagesRef.current.readConversationMetadata(this.conversationId);
    };
    hasLoadedMessage = (messageId) => (this.chatStore.getState().messages ?? []).some((message) => message.id === messageId);
}
/** The run status alone, for code outside the chat: no reads behind the rules. */
class RunStatusOnlyLiveUpdateReads {
    showRunStatus;
    constructor(showRunStatus) {
        this.showRunStatus = showRunStatus;
    }
    readChangedMessages = () => undefined;
    readConversationMetadata = () => undefined;
    hasLoadedMessage = () => true;
}
/**
 * The chat's live updates over `subscribeToConversationChanges`: one subscription per conversation,
 * the rules of `ConversationChangeSignalRules`, the reads written into the chat store.
 */
export const useConversationLiveUpdates = (inputs) => {
    const { client, chatStore, messagePages, conversationId } = inputs;
    const [runStatus, setRunStatus] = useState(null);
    const [liveReadCount, setLiveReadCount] = useState(0);
    const messagePagesRef = useRef(messagePages);
    messagePagesRef.current = messagePages;
    useEffect(() => {
        // A client without a subscription follows nothing here; `AgentChat` fills in the polling default before.
        if (!conversationId || !client.subscribeToConversationChanges)
            return;
        const rules = new ConversationChangeSignalRules(new ChatStoreLiveUpdateReads(chatStore, messagePagesRef, conversationId, setLiveReadCount, setRunStatus));
        const unsubscribe = client.subscribeToConversationChanges(conversationId, rules.receiveSignal, rules.receiveEnd);
        return () => {
            rules.dispose();
            unsubscribe();
        };
    }, [client, chatStore, conversationId]);
    const readChangedTailNow = useCallback(() => {
        if (!conversationId)
            return;
        setLiveReadCount((count) => count + 1);
        messagePagesRef.current.refetchChangedTail(conversationId);
    }, [conversationId]);
    return { runStatus, liveReadCount, readChangedTailNow };
};
/**
 * The run status of a conversation for code outside the chat (the launcher button, the end of a voice call), over the
 * same port function and the same status rules as the chat — without reading any message.
 */
export const useAgentsConversationRunStatus = (client, conversationId) => {
    const [runStatus, setRunStatus] = useState(null);
    useEffect(() => {
        // A client without a subscription reports nothing; wrap it in `withPollingChangeSubscription` first.
        if (!conversationId || !client.subscribeToConversationChanges)
            return;
        const rules = new ConversationChangeSignalRules(new RunStatusOnlyLiveUpdateReads(setRunStatus));
        const unsubscribe = client.subscribeToConversationChanges(conversationId, rules.receiveSignal, rules.receiveEnd);
        return () => {
            rules.dispose();
            unsubscribe();
        };
    }, [client, conversationId]);
    return runStatus;
};
