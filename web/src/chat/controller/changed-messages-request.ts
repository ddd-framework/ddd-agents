import type { ChangedMessagesRequest, ConversationMessageStatus } from "../../contract/conversation";
import type { ChatMessage } from "../chat-message";
import { hasDelegationInFlight, hasUnresolvedToolCall } from "./derive-agent-activity";

/** Row statuses that are not final yet (today only a waiting signal row carries one, PENDING until a turn reads it). */
const CHANGING_STATUSES: string[] = ["PENDING", "RUNNING", "CANCEL_REQUESTED"] satisfies ConversationMessageStatus[];

const canStillChange = (message: ChatMessage): boolean =>
  CHANGING_STATUSES.includes(message.status ?? "") || hasUnresolvedToolCall(message) || hasDelegationInFlight(message);

/**
 * What a live update reads, given the loaded messages: every row written since the newest loaded one, plus every
 * message from the lowest one that can still change. "Written since" goes by id, not index: ids grow with every write,
 * while an index can be taken below the newest loaded one (a call row persisted after the row its execution emitted)
 * or reused (a re-drive after an admin delete-from). The index start never lies below the newest page (a newest-first
 * page of `pageSize` cannot return older rows anyway), so a row a dead worker left pending cannot pin it. Nothing
 * loaded yet → null: the caller reads the newest page as on open. How the request reaches the wire (the filter string)
 * is the binding's matter (`AgentsConversationClient.getChangedMessages`).
 */
export const buildChangedMessagesRequest = (
  messages: ChatMessage[] | null,
  pageSize: number,
): ChangedMessagesRequest | null => {
  const loaded = (messages ?? []).filter((message) => message.indexInConversation != null);
  if (loaded.length === 0) {
    return null;
  }
  const indexes = (list: ChatMessage[]) => list.map((message) => message.indexInConversation as number);
  const highestIndex = Math.max(...indexes(loaded));
  const changing = loaded.filter(canStillChange);
  const fromIndex = Math.max(
    changing.length > 0 ? Math.min(...indexes(changing)) : highestIndex + 1,
    highestIndex - pageSize + 1,
  );
  const highestKnownId = Math.max(...loaded.map((message) => message.id ?? 0));
  return { highestKnownId, fromIndex, pageSize };
};
