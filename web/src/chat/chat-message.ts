import type { AgentsConversationMessage } from "../contract/conversation";

/**
 * A message as the chat holds and renders it: a stored message of the conversation contract, or the owner's optimistic
 * message before the server stored it (no id, no index yet), plus the chat store's `fresh` tag — the message arrived
 * live, so its one-shot widgets may fire. Every contract field is optional here because the optimistic message carries
 * only its text and role.
 */
export type ChatMessage = Partial<AgentsConversationMessage> & {
  /** The message arrived live (not on open, not on a scroll-up page); its widgets are `justDelivered`. */
  fresh?: boolean;
};
