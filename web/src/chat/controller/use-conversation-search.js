import { useCallback } from "react";
import { useAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import { useDebouncedSemanticSearch } from "./use-debounced-semantic-search";
/** The size of every chat search answer: ten matches, excerpts of 420 characters. */
const CHAT_SEARCH_OPTIONS = { limit: 10, excerptLength: 420 };
/**
 * In-conversation "find in this chat" search state: the conversation client's `searchConversationMessages` returns
 * the matched hits of one conversation.
 */
export const useConversationMessageSearch = (conversationId, enabled) => {
    const conversationClient = useAgentsConversationClient();
    const fetchConversationMessageHits = useCallback((trimmedQuery) => conversationClient.searchConversationMessages(conversationId, trimmedQuery, CHAT_SEARCH_OPTIONS), [conversationClient, conversationId]);
    const { results: hits, ...searchState } = useDebouncedSemanticSearch(fetchConversationMessageHits, enabled);
    return {
        hits,
        ...searchState,
    };
};
/**
 * Cross-conversation "search all my chats" state: the conversation client's `searchConversations` returns the
 * matches grouped by conversation.
 */
export const useConversationsSearch = (enabled) => {
    const conversationClient = useAgentsConversationClient();
    const fetchConversationSearchGroups = useCallback((trimmedQuery) => conversationClient.searchConversations(trimmedQuery, CHAT_SEARCH_OPTIONS), [conversationClient]);
    const { results: searchGroups, ...searchState } = useDebouncedSemanticSearch(fetchConversationSearchGroups, enabled);
    return {
        searchGroups,
        ...searchState,
    };
};
