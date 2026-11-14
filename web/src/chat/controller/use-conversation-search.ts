import { useCallback } from "react";
import { useAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import type {
  ConversationMessageSearchHit,
  ConversationSearchGroup,
  ConversationSearchOptions,
} from "../../contract/conversation";
import { useDebouncedSemanticSearch } from "./use-debounced-semantic-search";

/** The size of every chat search answer: ten matches, excerpts of 420 characters. */
const CHAT_SEARCH_OPTIONS: ConversationSearchOptions = { limit: 10, excerptLength: 420 };

export interface ConversationMessageSearchState {
  query: string;
  setQuery: (query: string) => void;
  trimmedQuery: string;
  hits: ConversationMessageSearchHit[] | null;
  searching: boolean;
  isSearchActive: boolean;
  /** Wipe query + hits. The caller runs this in the click handler that OPENS the panel (see hook doc). */
  clearSearch: () => void;
}

export interface ConversationsSearchState {
  query: string;
  setQuery: (query: string) => void;
  trimmedQuery: string;
  searchGroups: ConversationSearchGroup[] | null;
  searching: boolean;
  isSearchActive: boolean;
  /** Wipe query + results. The caller runs this in the click handler that OPENS the panel (see hook doc). */
  clearSearch: () => void;
}

/**
 * In-conversation "find in this chat" search state: the conversation client's `searchConversationMessages` returns
 * the matched hits of one conversation.
 */
export const useConversationMessageSearch = (
  conversationId: number,
  enabled: boolean,
): ConversationMessageSearchState => {
  const conversationClient = useAgentsConversationClient();
  const fetchConversationMessageHits = useCallback(
    (trimmedQuery: string) =>
      conversationClient.searchConversationMessages(conversationId, trimmedQuery, CHAT_SEARCH_OPTIONS),
    [conversationClient, conversationId],
  );
  const { results: hits, ...searchState } = useDebouncedSemanticSearch<ConversationMessageSearchHit>(
    fetchConversationMessageHits,
    enabled,
  );

  return {
    hits,
    ...searchState,
  };
};

/**
 * Cross-conversation "search all my chats" state: the conversation client's `searchConversations` returns the
 * matches grouped by conversation.
 */
export const useConversationsSearch = (enabled: boolean): ConversationsSearchState => {
  const conversationClient = useAgentsConversationClient();
  const fetchConversationSearchGroups = useCallback(
    (trimmedQuery: string) => conversationClient.searchConversations(trimmedQuery, CHAT_SEARCH_OPTIONS),
    [conversationClient],
  );
  const { results: searchGroups, ...searchState } = useDebouncedSemanticSearch<ConversationSearchGroup>(
    fetchConversationSearchGroups,
    enabled,
  );

  return {
    searchGroups,
    ...searchState,
  };
};
