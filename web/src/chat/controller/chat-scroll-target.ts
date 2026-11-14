/**
 * A request to scroll the chat to a specific message and flash it. Produced when a search result is clicked
 * (the app's search → the chat host) and consumed by the chat controller. `nonce` changes on every request so clicking the
 * same result twice re-triggers the scroll even though messageId/indexInConversation are unchanged.
 */
export type ChatScrollTarget = {
  messageId?: number | null;
  indexInConversation?: number | null;
  nonce: number;
};
