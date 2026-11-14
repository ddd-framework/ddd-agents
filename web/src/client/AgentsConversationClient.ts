import type {
  AgentsConversation,
  ChangedConversationMessages,
  ChangedMessagesRequest,
  ConversationActivity,
  ConversationAttachmentReference,
  ConversationChangeSignal,
  ConversationListQueryOptions,
  ConversationMessageSearchHit,
  ConversationMessageSentPayload,
  ConversationMessagesPage,
  ConversationSearchGroup,
  ConversationSearchOptions,
  ConversationSignal,
  ConversationSubscriptionEndReason,
  PostedMessage,
  SelectableModel,
  StartedConversation,
} from "../contract/conversation";

/**
 * The conversation client port (one function per row of the table, derived from the chat
 * controller's requests). The package calls only this; an app binds it over its own request layer; the package
 * ships an in-memory binding for specs, stories and the reference app (`client/testing/`).
 *
 * Rules every binding keeps:
 * - ids are numbers; the agent space key is passed where the endpoint needs it;
 * - every function rejects with `AgentsClientError` (`contract/conversation.ts`), never with a transport error;
 * - a send returns once the server stored it — the agent's reply arrives through `subscribeToConversationChanges`;
 * - the package adds the optimistic owner message before it calls a send and pairs request and answer itself;
 * - a purposed start is not part of the port (it stays an app call).
 */
export interface AgentsConversationClient {
  /** The conversation without messages: title, run state, the owed-turn mark, purpose, initiator. */
  getConversationMetadata: (conversationId: number) => Promise<AgentsConversation>;

  /** The newest page, each message with its delegated children's `{ id, runState }`. */
  getNewestMessagesPage: (conversationId: number, pageSize: number) => Promise<ConversationMessagesPage>;

  /** The page before `beforeIndex` (scroll-up). */
  getOlderMessagesPage: (conversationId: number, beforeIndex: number, pageSize: number) => Promise<ConversationMessagesPage>;

  /** New rows and rows that can still change (a live update); the package computes the request and keeps `hasMoreOlder`. */
  getChangedMessages: (conversationId: number, request: ChangedMessagesRequest) => Promise<ChangedConversationMessages>;

  /**
   * Start a conversation with its first message. `text` may be empty only together with `startSignalText` (the agent
   * speaks first); `entryPointKey` reopens the recent conversation of that entry point instead of starting an
   * identical one; `model` is a name from `getSelectableModels`.
   */
  startConversation: (request: {
    agentSpaceKey: number;
    text: string;
    startSignalText?: string;
    entryPointKey?: string;
    model?: string;
    attachments?: ConversationAttachmentReference[];
  }) => Promise<StartedConversation>;

  /** Post the owner's message. Allowed while a turn runs: the server answers it in the next turn. */
  postMessage: (
    conversationId: number,
    message: { text: string; model?: string; attachments?: ConversationAttachmentReference[] },
  ) => Promise<PostedMessage>;

  /** Inject a model-visible, owner-invisible signal (a widget click) and let the agent take a turn on it. */
  injectSignal: (conversationId: number, signal: ConversationSignal) => Promise<void>;

  /** Re-drive the failed run on the stored, still unanswered owner message. */
  retryConversation: (conversationId: number) => Promise<void>;

  /** Set the title by hand; returns the conversation with the stored title. */
  renameConversation: (conversationId: number, title: string) => Promise<AgentsConversation>;

  deleteConversation: (conversationId: number) => Promise<void>;

  /** The root conversations of an agent space, newest first. */
  listConversations: (agentSpaceKey: number, options?: ConversationListQueryOptions) => Promise<AgentsConversation[]>;

  /** Search across the account's conversations, grouped by conversation. */
  searchConversations: (text: string, options?: ConversationSearchOptions) => Promise<ConversationSearchGroup[]>;

  /** Search inside one conversation. */
  searchConversationMessages: (
    conversationId: number,
    text: string,
    options?: ConversationSearchOptions,
  ) => Promise<ConversationMessageSearchHit[]>;

  /** What the agent does in the current turn; `turnMessageId` pins the narrated turn once known. */
  getConversationActivity: (conversationId: number, turnMessageId?: number | null) => Promise<ConversationActivity>;

  /** The models the owner may pick. Optional: without it the chat shows no model picker. */
  getSelectableModels?: () => Promise<SelectableModel[]>;

  /**
   * The text the server stores for a message with attachments — the package shows the optimistic owner message with
   * it, so the bubble does not change when the stored message arrives. Optional: without it the text stays as typed
   * (a binding that sends attachments in a field of their own).
   */
  composeSentMessageText?: (text: string, attachments: ConversationAttachmentReference[] | undefined) => string;

  /** The reconstructed request of a model call (the admin details panel). Optional. */
  getMessageSentPayload?: (conversationId: number, messageId: number) => Promise<ConversationMessageSentPayload>;

  /**
   * Live updates: `onSignal` receives the conversation's signal as data, the first one right after the
   * subscription is up; `onEnded` reports that the conversation is gone or no longer accessible (`gone`, `forbidden`) —
   * the binding stops its own reads for it then (run-state checks), the package clears the status; a push transport may
   * keep delivering until the unsubscribe. Returns the unsubscribe. The rules on top (throttle, terminal re-read,
   * convergence on `lastMessageId`) are the package's; transport health (connection watchdog, token cache) is the
   * binding's.
   *
   * Optional: a binding without a push transport leaves it out, and `withPollingChangeSubscription`
   * (`client/polling/`) fills it with the polling default over `getConversationMetadata`; the default `AgentChat` does
   * that by itself. `AgentChatController` and `useAgentsConversationRunStatus` follow nothing for a client without it.
   */
  subscribeToConversationChanges?: (
    conversationId: number,
    onSignal: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ) => () => void;
}

/** The port functions by name — the `operation` that `extraRequestParameters` receives. */
export type AgentsConversationOperation = Exclude<keyof AgentsConversationClient, "subscribeToConversationChanges">;

/** Extra query parameters a binding stamps onto a request: an app's own routing tags, admin overrides. */
export type AgentsExtraRequestParameters = Record<string, string | number | boolean | undefined>;

/** Options every binding that issues HTTP requests takes. */
export interface AgentsConversationClientBindingOptions {
  /**
   * App-only request parameters per operation, merged over the binding's own (the host application's own
   * overrides and its chat partition tag). Read on every request, so it may read
   * state that changes at runtime. The package never names these parameters.
   */
  extraRequestParameters?: (operation: AgentsConversationOperation) => AgentsExtraRequestParameters;
}
