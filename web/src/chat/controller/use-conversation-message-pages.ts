import { useCallback, useMemo, useRef, useState } from "react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import type { AgentsChatStore } from "../store/create-agents-chat-store";
import { buildChangedMessagesRequest } from "./changed-messages-request";

/** The page size of every message read of the chat: the newest page, the changed tail and an older page. */
export const CONVERSATION_MESSAGE_PAGE_SIZE = 50;

export interface ConversationMessagePages {
  /** Reads the conversation without messages into the store; resolves false when the read was refused. */
  readConversationMetadata: (conversationId: number) => Promise<boolean>;
  /** The newest page an open reads; a changed-tail read asked meanwhile waits for it. */
  fetchOpeningPage: (conversationId: number) => void;
  /** The rows written since the newest loaded one and the rows that can still change (a live update). */
  refetchChangedTail: (conversationId: number) => void;
  /** The page before the oldest loaded message (scroll-up); guarded against a second load and an exhausted history. */
  loadOlder: (conversationId: number | undefined) => Promise<void>;
  /** An older page is on its way. */
  loadingOlder: boolean;
}

/**
 * The chat's message reads over the conversation client port, written into the chat store: the newest page of an
 * open, the changed tail every live update asks for, an older page on scroll-up, and the metadata read. A changed-tail
 * read asked while the open's newest page is still on its way would read that same page again (nothing is loaded yet,
 * so it has no filter): it is held instead and sent once the page is in, reading past what the page brought, so a
 * reply stored in between still arrives. A failed page read changes nothing (the next live update reads again).
 */
export const useConversationMessagePages = (
  chatStore: AgentsChatStore,
  client: AgentsConversationClient,
  pageSize: number = CONVERSATION_MESSAGE_PAGE_SIZE,
): ConversationMessagePages => {
  const openingPageRef = useRef<{ conversationId: number; refetchOwed: boolean } | null>(null);
  const [loadingOlder, setLoadingOlder] = useState(false);
  const loadingOlderRef = useRef(false);

  const readConversationMetadata = useCallback(
    async (conversationId: number): Promise<boolean> => {
      chatStore.dispatch({ type: "conversationMetadataRequested", conversationId });
      try {
        const conversation = await client.getConversationMetadata(conversationId);
        chatStore.dispatch({ type: "conversationMetadataReceived", conversationId, conversation });
        return true;
      } catch {
        chatStore.dispatch({ type: "conversationMetadataFailed", conversationId });
        return false;
      }
    },
    [chatStore, client],
  );

  // Reads the store, not the rendered messages: the held read runs before the opened page has re-rendered.
  const fetchChangedTail = useCallback(
    async (conversationId: number): Promise<void> => {
      const changedMessagesRequest = buildChangedMessagesRequest(chatStore.getState().messages, pageSize);
      chatStore.dispatch({ type: "messagesPageRequested", conversationId });
      try {
        if (changedMessagesRequest === null) {
          // Nothing loaded yet: the newest page, as on open.
          const page = await client.getNewestMessagesPage(conversationId, pageSize);
          chatStore.dispatch({
            type: "messagesPageReceived",
            conversationId,
            messages: page.messages,
            hasMoreOlder: page.hasMoreOlder,
          });
          return;
        }
        const changedMessages = await client.getChangedMessages(conversationId, changedMessagesRequest);
        chatStore.dispatch({
          type: "messagesPageReceived",
          conversationId,
          messages: changedMessages.messages,
          hasMoreOlder: null,
        });
      } catch {
        // A failed live read changes nothing; the next signal reads again.
      }
    },
    [chatStore, client, pageSize],
  );

  const fetchOpeningPage = useCallback(
    (conversationId: number) => {
      const openingPage = { conversationId, refetchOwed: false };
      openingPageRef.current = openingPage;
      chatStore.dispatch({ type: "messagesPageRequested", conversationId });
      const settleOpeningPage = () => {
        if (openingPageRef.current !== openingPage) return;
        openingPageRef.current = null;
        // A reset since (empty chat, closed window) has nothing left to read into.
        const isStillShown = chatStore.getState().requestedConversationId === conversationId;
        if (openingPage.refetchOwed && isStillShown) {
          void fetchChangedTail(conversationId);
        }
      };
      client
        .getNewestMessagesPage(conversationId, pageSize)
        .then((page) => {
          chatStore.dispatch({
            type: "messagesPageReceived",
            conversationId,
            messages: page.messages,
            hasMoreOlder: page.hasMoreOlder,
          });
        })
        .catch(() => undefined)
        .then(settleOpeningPage);
    },
    [chatStore, client, pageSize, fetchChangedTail],
  );

  const refetchChangedTail = useCallback(
    (conversationId: number) => {
      const openingPage = openingPageRef.current;
      if (openingPage?.conversationId === conversationId) {
        openingPage.refetchOwed = true;
        return;
      }
      void fetchChangedTail(conversationId);
    },
    [fetchChangedTail],
  );

  const loadOlder = useCallback(
    async (conversationId: number | undefined): Promise<void> => {
      const { hasMoreOlder, messages } = chatStore.getState();
      if (loadingOlderRef.current || !hasMoreOlder || !conversationId) return;
      const lowestLoadedIndex = (messages ?? [])[0]?.indexInConversation;
      if (lowestLoadedIndex == null) return;
      loadingOlderRef.current = true;
      setLoadingOlder(true);
      chatStore.dispatch({ type: "messagesPageRequested", conversationId });
      try {
        const page = await client.getOlderMessagesPage(conversationId, lowestLoadedIndex, pageSize);
        chatStore.dispatch({
          type: "messagesPageReceived",
          conversationId,
          messages: page.messages,
          hasMoreOlder: page.hasMoreOlder,
        });
      } catch {
        // The scroll-up load stays armed; the next sentinel hit tries again.
      } finally {
        loadingOlderRef.current = false;
        setLoadingOlder(false);
      }
    },
    [chatStore, client, pageSize],
  );

  return useMemo(
    () => ({ readConversationMetadata, fetchOpeningPage, refetchChangedTail, loadOlder, loadingOlder }),
    [readConversationMetadata, fetchOpeningPage, refetchChangedTail, loadOlder, loadingOlder],
  );
};
