import type { ChatMessage } from "../chat-message";

/**
 * Merges two message lists by `indexInConversation`, preferring `incoming` on a collision, sorted ascending by index.
 * Messages without an index (the optimistic owner message) cannot be matched, so they follow the indexed ones in order.
 * A start or post answer carries the whole list and a live read brings it again: concatenating would show every
 * message twice.
 */
export const mergeChatMessagesByIndex = (existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] => {
  const messagesByIndex = new Map<number, ChatMessage>();
  const messagesWithoutIndex: ChatMessage[] = [];
  const collectMessage = (message: ChatMessage) => {
    if (message.indexInConversation != null) {
      messagesByIndex.set(message.indexInConversation, message);
    } else {
      messagesWithoutIndex.push(message);
    }
  };
  existing.forEach(collectMessage);
  incoming.forEach(collectMessage);
  const indexedMessages = Array.from(messagesByIndex.values()).sort(
    (first, second) => (first.indexInConversation ?? 0) - (second.indexInConversation ?? 0),
  );
  return [...indexedMessages, ...messagesWithoutIndex];
};

/**
 * Tags the messages of a send's answer: `fresh` when the id was not in the list before — the server just delivered it,
 * so its one-shot widgets may fire; a replayed message never fires them again.
 */
export const tagFreshChatMessages = (incoming: ChatMessage[], previousMessages: ChatMessage[]): ChatMessage[] => {
  const previousIds = new Set(previousMessages.map((message) => message.id).filter((id): id is number => id != null));
  return incoming.map((message) => ({ ...message, fresh: message.id != null && !previousIds.has(message.id) }));
};

/**
 * Merges a page of a keyset read (the newest page, an older page, the changed tail) into the loaded messages by index.
 * A message is `fresh` only when it is a live arrival: never shown before and newer than everything loaded — so the
 * streamed reply opens its canvas while a scroll-up page and the first page on open stay history. A message already
 * shown keeps its tag. A page of another conversation than the one shown replaces the list.
 */
export const mergeMessagesPage = (
  loadedMessages: ChatMessage[] | null,
  page: ChatMessage[],
  requestedConversationId: number,
): ChatMessage[] => {
  const shownConversationId = loadedMessages?.find((message) => message.conversationId != null)?.conversationId;
  const replacesShownConversation = shownConversationId != null && shownConversationId !== requestedConversationId;
  const existingMessages = replacesShownConversation ? [] : (loadedMessages ?? []);
  const messagesByIndex = new Map<number, ChatMessage>();
  for (const existingMessage of existingMessages) {
    if (existingMessage.indexInConversation != null) {
      messagesByIndex.set(existingMessage.indexInConversation, existingMessage);
    }
  }
  const hadMessagesBeforeMerge = existingMessages.length > 0;
  const highestShownIndex = Math.max(-1, ...existingMessages.map((message) => message.indexInConversation ?? -1));
  for (const incomingMessage of page) {
    if (incomingMessage.indexInConversation == null) continue;
    const previousMessage = messagesByIndex.get(incomingMessage.indexInConversation);
    const isLiveArrival =
      hadMessagesBeforeMerge && previousMessage == null && incomingMessage.indexInConversation > highestShownIndex;
    messagesByIndex.set(incomingMessage.indexInConversation, {
      ...incomingMessage,
      fresh: previousMessage?.fresh ?? isLiveArrival,
    });
  }
  return Array.from(messagesByIndex.values()).sort(
    (first, second) => (first.indexInConversation ?? 0) - (second.indexInConversation ?? 0),
  );
};
