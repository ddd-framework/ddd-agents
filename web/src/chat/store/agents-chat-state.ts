import type {
  AgentsClientError,
  AgentsConversation,
  AgentsConversationMessage,
  PostedMessage,
  StartedConversation,
} from "../../contract/conversation";
import type { ChatMessage } from "../chat-message";
import type { CanvasCreatedEntity, CanvasSliceState } from "../../renderer/canvas/canvas-reducers";
import type { CanvasOpenRequest } from "../../renderer/canvas/canvas-state";
import type { ConversationErrorType } from "./conversation-error-type";

/**
 * How an entry point outside the chat hands a job over to the chat that opens next (one generic opening
 * request): the first visible message, or an invisible start signal so the agent speaks first, the entry point the
 * start comes from, and a widget to mount without a message (a page handing over a tool). Set before the chat mounts,
 * so a reset keeps it; the chat clears each part once it consumed it.
 */
export interface AgentsChatOpeningRequest {
  /** The first visible owner message of the next started conversation. */
  userMessage: string | null;
  /** The invisible start signal of the next started conversation (an empty visible message carries it). */
  startSignalText: string | null;
  /** The entry point the start comes from; the server reopens that entry point's recent conversation instead. */
  entryPointKey: string | null;
  /** The canonical wire name of a deep-linkable widget the chat mounts once, without a message. */
  deepLinkedWidgetName: string | null;
}

/** The state of one chat instance. */
export interface AgentsChatState extends CanvasSliceState {
  /** The conversation shown; null for a cold chat until a start or the metadata read brings it. */
  conversation: AgentsConversation | null;
  /** Null until the first page or a send; ordered by `indexInConversation`, the optimistic message last. */
  messages: ChatMessage[] | null;
  /** A send is on its way. */
  conversationLoading: boolean;
  /** The last read or send for this conversation came back. */
  conversationLoaded: boolean;
  /** An older page exists: the scroll-up load stays armed. */
  hasMoreOlder: boolean;
  /** The agent works on a turn (the controller mirrors the derived activity here; read by the launcher). */
  aiThinking: boolean;
  error: ConversationErrorType | null;
  /** Seconds until the quota frees up again; set only while `error` is RATE_LIMIT. */
  rateLimitRetryAfterSeconds: number | null;
  /**
   * The conversation the newest metadata or page read asked for; null after a reset. A late answer for another
   * conversation — or one that lands after a reset — is dropped.
   */
  requestedConversationId: number | null;
  /** A start is on its way; no second start is sent meanwhile. */
  startingNewConversation: boolean;
  /** The token of the start on its way; its answer applies only while it is still the pending one. */
  pendingStartToken: number | null;
  /**
   * How many starts this chat instance sent — the source of `pendingStartToken`. Survives a reset (like
   * `canvasMountCount`): the store outlives a remount of the chat, so a token is never reused for another start.
   */
  startTokenCount: number;
  openingRequest: AgentsChatOpeningRequest;
}

export const EMPTY_AGENTS_CHAT_OPENING_REQUEST: AgentsChatOpeningRequest = {
  userMessage: null,
  startSignalText: null,
  entryPointKey: null,
  deepLinkedWidgetName: null,
};

export const INITIAL_AGENTS_CHAT_STATE: AgentsChatState = {
  conversation: null,
  messages: null,
  conversationLoading: false,
  conversationLoaded: false,
  hasMoreOlder: false,
  aiThinking: false,
  error: null,
  rateLimitRetryAfterSeconds: null,
  requestedConversationId: null,
  startingNewConversation: false,
  pendingStartToken: null,
  startTokenCount: 0,
  openingRequest: EMPTY_AGENTS_CHAT_OPENING_REQUEST,
  canvas: null,
  lastClosedCanvas: null,
  canvasMountCount: 0,
};

/**
 * What happens to a chat instance. The read and send events carry the port's answers (`AgentsConversationClient`);
 * the controller dispatches the `…Requested` event before it calls the port and the `…Received` / `…Failed` event
 * with the answer.
 */
export type AgentsChatEvent =
  /** A conversation switch, an empty chat, the chat's unmount: everything goes except the opening request and the two counters. */
  | { type: "reset" }
  | { type: "conversationMetadataRequested"; conversationId: number }
  | { type: "conversationMetadataReceived"; conversationId: number; conversation: AgentsConversation }
  | { type: "conversationMetadataFailed"; conversationId: number }
  | { type: "messagesPageRequested"; conversationId: number }
  /** `hasMoreOlder` null: a changed-messages read, which says nothing about older pages. */
  | {
      type: "messagesPageReceived";
      conversationId: number;
      messages: AgentsConversationMessage[];
      hasMoreOlder: boolean | null;
    }
  | { type: "messagePostRequested"; message: ChatMessage }
  | { type: "messagePostReceived"; conversationId: number; posted: PostedMessage }
  | { type: "messagePostFailed"; error: AgentsClientError }
  /** Takes the next start token (`pendingStartToken`); the controller reads it after the dispatch. */
  | { type: "startRequested"; message: ChatMessage }
  | { type: "startReceived"; startToken: number; started: StartedConversation }
  | { type: "startFailed"; error: AgentsClientError }
  | { type: "errorSet"; error: ConversationErrorType | null }
  | { type: "aiThinkingSet"; aiThinking: boolean }
  /** Sets the named parts of the opening request; the others stay. */
  | { type: "openingRequestSet"; openingRequest: Partial<AgentsChatOpeningRequest> }
  | { type: "canvasOpened"; request: CanvasOpenRequest }
  | { type: "canvasClosed"; byCustomer: boolean }
  | { type: "canvasResetForNewTurn" }
  | { type: "canvasCleared" }
  | { type: "canvasCreatedEntityIdSet"; created: CanvasCreatedEntity };
