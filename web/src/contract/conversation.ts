/**
 * The conversation contract of the package: plain TypeScript types the conversation client port
 * (`client/AgentsConversationClient.ts`) returns and the chat reads. Every field is derived from what the chat files and
 * the chat controller read from the server today (the host application's generated `AIConversation`, `AIConversationMessage`,
 * `AgentActivityLine`, `SemanticSearchHit`, `AIConversationSearchGroup`, `AgentCustomerSelectableModel`); nothing the
 * chat does not read is here.
 *
 * Naming: a field keeps the wire name where the name is neutral (`message`, `indexInConversation`, `role`, `status`,
 * `toolCall`); a name that carries an app's vocabulary is renamed (`aiConversationId` → `conversationId`,
 * the app's own key of the thing the agent works for → `agentSpaceKey`, `summary.title` → `title`). Each field carries a
 * one-line note:
 * - `generic` — every app that runs the agent conversation backend has it;
 * - `app extension` — read by the chat today but specific to one app's backend or debug surface; optional, and a
 *   binding may leave it out.
 *
 * Client-side state is NOT part of the contract: the `fresh` tag of a live-arrived message, the optimistic user
 * message without an id and the `isNewConversation` / `isReopenedConversation` flags belong to the package store.
 */

// ── Vocabulary ───────────────────────────────────────────────────────────────────────────────────────────────────

/** The run state of a conversation as the server stores it (`AIConversationRunState`). */
export type ConversationRunState =
  | "IDLE"
  | "QUEUED"
  | "RUNNING"
  | "AWAITING_DELEGATES"
  | "FAILED"
  | "COMPLETED"
  | "CANCELLED";

/** The three-value run status the live-update signal speaks; the chat keys its indicator and error card off it. */
export type ConversationRunStatus = "running" | "idle" | "failed";

export type ConversationMessageRole = "SYSTEM" | "USER" | "ASSISTANT";

export type ConversationMessageType = "TEXT" | "TOOL_CALL" | "CHECKPOINT" | "ERROR" | "SIGNAL" | "TOOL_MESSAGE";

export type ConversationMessageStatus =
  | "PENDING"
  | "RUNNING"
  | "CANCEL_REQUESTED"
  | "CANCELLED"
  | "COMPLETED"
  | "FAILED";

export type ConversationMessageAuthorType = "USER" | "ASSISTANT" | "AGENT" | "TOOL";

/** Run states after which nothing runs any more and the server refuses further customer input. */
export const CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION: readonly ConversationRunState[] = [
  "COMPLETED",
  "CANCELLED",
];

/** Maps a stored run state onto the signal's three-value status (`runStateToSignalStatus` of the live-update hook). */
export const readConversationRunStatusOfRunState = (
  runState: string | null | undefined,
): ConversationRunStatus | null => {
  if (!runState) return null;
  if (runState === "FAILED") return "failed";
  if (runState === "RUNNING" || runState === "AWAITING_DELEGATES" || runState === "QUEUED") return "running";
  return "idle";
};

// ── Conversation ─────────────────────────────────────────────────────────────────────────────────────────────────

/** One step of the progress stepper a purposed conversation declares (`CustomerProgressStep`). */
export interface ConversationProgressStep {
  /** generic */
  key: string;
  /** generic — already in the owner's language */
  label: string;
  /** generic */
  status: "done" | "active" | "upcoming";
}

/** A conversation as the chat, the conversation list and the window around the chat read it. */
export interface AgentsConversation {
  /** generic */
  id: number;
  /** generic — the key of the agent space the conversation belongs to */
  agentSpaceKey: number | null;
  /** generic */
  runState: ConversationRunState | null;
  /**
   * generic — set while the server owes a turn on an IDLE conversation (a failed hand-over the recovery re-drives);
   * an IDLE conversation with this set counts as running (`effectiveRunState`)
   */
  agentTurnOwedAt: string | null;
  /** generic — the display title (`summary.title`); null until the first title was generated */
  title: string | null;
  /** generic — the owner set the title by hand; the generated title never replaces it then */
  titleManuallyEdited: boolean;
  /** generic — the generated summary of what the conversation is about (`summary.text`); null until generated */
  summary: string | null;
  /** generic — when the conversation was created, in the server's date-time format */
  created: string | null;
  /** generic — when the conversation was last modified, in the server's date-time format */
  updated: string | null;
  /** generic — the purpose of a purposed conversation (e.g. `Example.Build`, a first-run setup purpose); null otherwise */
  purposeName: string | null;
  /** generic — who started the conversation (`SCHEDULE` for a run of a scheduled task) */
  initiatorType: string | null;
  /** generic — the scheduled task a `SCHEDULE` conversation is a run of */
  scheduleId: number | null;
  /** generic — the entry point a start came from; a start from the same entry point reopens this conversation */
  entryPointKey: string | null;
  /** generic — empty unless the purpose declares a progress stepper */
  progressSteps: ConversationProgressStep[];
  /**
   * generic — the id of the newest stored message; null before the first. The polling default of the live updates
   * (`client/polling/`) reads it to see a new message while the run status stays the same, and hands it to the rules as
   * the signal's `lastMessageId`. Optional: a binding with a push transport may leave it out (the host application's metadata
   * read does not carry it); without it the poller treats every poll of a running turn as a change.
   */
  lastMessageId?: number | null;
  /** app extension — the prompt extension of the host application's older AI-Extensions chat */
  promptExtensionId?: number | null;
  /** app extension — the category of that prompt extension (its colour and product name in the conversation list) */
  promptExtensionCategory?: { alias: string | null; name: string | null } | null;
}

// ── Messages ─────────────────────────────────────────────────────────────────────────────────────────────────────

/** A sub-agent conversation a delegation started, as far as the chat reads it (`delegatedConversations(select=id,runState)`). */
export interface ConversationDelegatedConversation {
  /** generic */
  id: number;
  /** generic */
  runState: ConversationRunState | null;
}

/** One run of a sub-agent, as the activity derivation reads it (`DelegationSubAgentRun`). */
export interface ConversationSubAgentRun {
  /** generic */
  runNumber: number | null;
  /** generic */
  status: string | null;
}

/** The sub-agent record a newer `delegate` call row carries instead of expanded children (`DelegationSubAgentRecord`). */
export interface ConversationSubAgent {
  /** generic */
  currentRunNumber: number | null;
  /** generic */
  runs: ConversationSubAgentRun[];
}

/** The tool call a `TOOL_CALL` row carries, as far as the chat reads it. */
export interface ConversationToolCall {
  /** generic */
  toolCallId: string | null;
  /** generic */
  name: string | null;
  /** generic — the call's arguments as the model wrote them (the `delegate` call's `tasks` is read) */
  arguments: unknown;
  /** generic — the call's own progress; a row's `status` says nothing about it */
  status: string | null;
}

/** The admin debug numbers of one message (the message forensics panel). */
export interface ConversationMessageForensics {
  /** app extension */
  inputTokens: number | null;
  /** app extension */
  outputTokens: number | null;
  /** app extension */
  costInUSD: number | null;
  /** app extension — the stored request snapshot of the model call (model name, reasoning effort, sent at) */
  requestSnapshot: unknown;
}

/** One stored message of a conversation. */
export interface AgentsConversationMessage {
  /** generic */
  id: number;
  /** generic */
  conversationId: number | null;
  /** generic — the position in the conversation; the merge key of every page */
  indexInConversation: number | null;
  /** generic */
  role: ConversationMessageRole | null;
  /** generic */
  messageType: ConversationMessageType | null;
  /** generic — non-final only on a waiting signal row */
  status: ConversationMessageStatus | null;
  /** generic */
  authorType: ConversationMessageAuthorType | null;
  /** generic — the source of a SIGNAL or ERROR row (`AI_BUDGET_REACHED`, `FRONTEND_WIDGET_CLICK`, …) */
  signalSource: string | null;
  /** generic — the text in markdown, widget blocks included */
  message: string;
  /** generic */
  createdDate: string | null;
  /** generic */
  toolCall: ConversationToolCall | null;
  /** generic — the sub-agent conversations a `delegate` call started (older rows) */
  delegatedConversations: ConversationDelegatedConversation[];
  /** generic — the sub-agent record of a `delegate` call (newer rows) */
  subAgent: ConversationSubAgent | null;
  /** app extension — admin debug numbers; absent outside the admin surfaces */
  forensics?: ConversationMessageForensics;
}

/** A file from the app's media library that rides along with a message. */
export interface ConversationAttachmentReference {
  /** generic — the app's id of the file (host application: the media manager file id) */
  fileId: string | number | null;
  /** generic */
  fileName: string | null;
  /** generic — a URL the model and the tools can fetch */
  publicUrl: string | null;
}

// ── Pages and results of the port ────────────────────────────────────────────────────────────────────────────────

/** A page of messages read newest first (the newest page on open, an older page on scroll-up). */
export interface ConversationMessagesPage {
  /** generic — in any order; the store merges by `indexInConversation` */
  messages: AgentsConversationMessage[];
  /** generic — an older page exists */
  hasMoreOlder: boolean;
}

/**
 * What a live update reads: every message written since the newest known id, plus every message from the lowest
 * loaded index that can still change (`build-live-refetch-query.ts`). The package computes both values.
 */
export interface ChangedMessagesRequest {
  /** generic — the highest message id loaded */
  highestKnownId: number;
  /** generic — the lowest index that can still change, never below the newest page */
  fromIndex: number;
  /** generic — the page size of the read; the binding's default (host application: 50, as the chat sends) when omitted */
  pageSize?: number;
}

/** The answer of a changed-messages read; it says nothing about older pages (the store keeps `hasMoreOlder`). */
export interface ChangedConversationMessages {
  /** generic */
  messages: AgentsConversationMessage[];
}

/** The answer of a start: the new conversation with what it holds so far, or the reopened one of the entry point. */
export interface StartedConversation {
  /** generic */
  conversation: AgentsConversation;
  /** generic — the stored messages of a new conversation; empty for a reopened one (the chat reads its newest page) */
  messages: AgentsConversationMessage[];
  /** generic — the start returned the recent conversation of its entry point; nothing was appended, no turn started */
  reopenedExisting: boolean;
}

/** The answer of a post: the conversation's messages so far (the reply arrives through live updates). */
export interface PostedMessage {
  /** generic */
  messages: AgentsConversationMessage[];
}

/** A model-visible, owner-invisible note into a conversation (a widget click, a render report, a correction). */
export interface ConversationSignal {
  /** generic */
  text: string;
  /** generic — the signal source; the server's default applies when omitted (a widget click) */
  source?: string;
}

/** How a list read pages and which conversations it includes. */
export interface ConversationListQueryOptions {
  /** generic — include conversations that have no summary (title) yet, e.g. a just-started one */
  includeUnsummarized?: boolean;
  /** generic — the host application's history reads the newest 50 */
  paging?: { top?: number; skip?: number };
}

/** The size of a search answer. */
export interface ConversationSearchOptions {
  /** generic — the most groups (or hits) returned; the host application's chat asks for 10 */
  limit?: number;
  /** generic — the excerpt length in characters; the host application's chat asks for 420 */
  excerptLength?: number;
}

/** app extension — the reconstructed request a model call was sent with (the admin details panel); opaque data. */
export type ConversationMessageSentPayload = unknown[];

/** One matched message of a message search (`SemanticSearchHit`). */
export interface ConversationMessageSearchHit {
  /** generic */
  messageId: number | null;
  /** generic */
  conversationId: number | null;
  /** generic */
  indexInConversation: number | null;
  /** generic */
  role: string | null;
  /** generic */
  excerpt: string;
  /** generic */
  relevance: number;
  /** generic */
  created: string | null;
  /** generic */
  updated: string | null;
  /** generic — the conversation's title */
  title: string | null;
}

/** One conversation of a search across conversations, with its matched messages (`AIConversationSearchGroup`). */
export interface ConversationSearchGroup {
  /** generic */
  conversationId: number | null;
  /** generic */
  title: string | null;
  /** generic */
  summary: string | null;
  /** generic */
  created: string | null;
  /** generic */
  updated: string | null;
  /** generic */
  relevance: number;
  /** generic */
  hits: ConversationMessageSearchHit[];
}

/** One generated line of what the agent is doing (`AgentActivityLine`). */
export interface ConversationActivityElement {
  /** generic — the row the line narrates; the merge key */
  toolCallMessageId: number;
  /** generic */
  batchId: string | null;
  /** generic */
  state: "queued" | "running" | "finished" | "failed" | "skipped" | null;
  /** generic */
  kind: "tool" | "delegation" | "background" | "long" | "turnStart" | null;
  /** generic */
  childCount: number | null;
  /** generic — the 1-based delegation helper that ran the step; null for the assistant's own steps */
  helperIndex: number | null;
  /** generic — the sentence; null while the narrator has not answered */
  line: string | null;
}

/** The activity of one turn (`GET …/activity`). */
export interface ConversationActivity {
  /** generic — the message that started the narrated turn */
  turnMessageId: number | null;
  /** generic */
  turnRunning: boolean;
  /** generic */
  elements: ConversationActivityElement[];
}

/** A model the owner (host application: an impersonating admin) may pick for the next turns (`AgentCustomerSelectableModel`). */
export interface SelectableModel {
  /** generic — the name a send carries */
  name: string;
  /** generic */
  displayName: string;
  /** generic */
  description: string;
  /** generic */
  strengthLevel: number | null;
  /** generic */
  speedLevel: number | null;
  /** app extension — the host application's credit multiplier of the model */
  creditsMultiplier?: number | null;
}

// ── Live updates ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * What a live update carries: a claim check, never content. The chat reads the conversation again when
 * `changeCounter` advances, at once on a terminal status, and until `lastMessageId` is loaded.
 */
export interface ConversationChangeSignal {
  /** generic — advances on every change of the conversation */
  changeCounter: number;
  /** generic — the newest message the server wrote; null before the first */
  lastMessageId: number | null;
  /** generic */
  runStatus: ConversationRunStatus | null;
  /** generic — advances when the title or summary was generated again (a light metadata read is enough then) */
  summaryUpdatedAt: number | null;
  /**
   * generic — the binding produced this emission from a run-state read, not from a pushed change (a binding without
   * push, or a push transport whose path is not proven readable, or a check that corrects a stale status). It carries
   * only `runStatus` as news: `changeCounter`, `lastMessageId` and `summaryUpdatedAt` repeat the last pushed values
   * (before any push: a counter that counts down from -1, never equal to a pushed one). The package sets the status
   * from it and reads the conversation again on a status change while the push path is not alive (`isPushPathAlive`).
   */
  isStatusCheck?: boolean;
  /**
   * generic, on a status-check emission — the binding's push path delivers: a pushed change arrived and the path was not
   * denied since. False after a denied read until a push arrives again; then the status checks are the live path and a
   * status change reads the conversation again. A binding without push leaves it out: the package then counts the
   * pushed changes it saw itself.
   */
  isPushPathAlive?: boolean;
}

/** Why a subscription ended for good: the conversation was deleted or is no longer readable, or the account lost access. */
export type ConversationSubscriptionEndReason = "gone" | "forbidden";

// ── Errors ───────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * What went wrong, as the chat tells the owner. The codes the chat's error handling reads today map
 * onto it: `AI_BUDGET_REACHED` → `budget`, HTTP 429 / `RATE_LIMIT_EXCEEDED` → `rateLimit`, `CONVERSATION_CLOSED` →
 * `closed`, the not-found and no-access answers → `notFound` / `forbidden` (`conversation-not-found.ts`), no answer or a
 * server failure → `network`, everything else (a refused retry, a cancelled request) → `other` with its `code`.
 */
export type AgentsClientErrorKind = "budget" | "rateLimit" | "closed" | "notFound" | "forbidden" | "network" | "other";

/** The code `other` carries for a request the client cancelled; the chat shows no error card for it. */
export const AGENTS_CLIENT_ERROR_CODE_REQUEST_ABORTED = "REQUEST_ABORTED";

/** The one error shape every port function rejects with. */
export class AgentsClientError extends Error {
  readonly kind: AgentsClientErrorKind;
  /** The server's stable error code, or the client's own code (`REQUEST_ABORTED`); null when none arrived. */
  readonly code: string | null;
  /** Seconds until a quota frees up again; set only for `rateLimit`. */
  readonly retryAfterSeconds: number | null;
  /** The HTTP status when one arrived (not part of the contract's shape; kept for diagnostics). */
  readonly httpStatus: number | null;

  constructor(
    kind: AgentsClientErrorKind,
    details: { message?: string; code?: string | null; retryAfterSeconds?: number | null; httpStatus?: number | null } = {},
  ) {
    super(details.message ?? kind);
    this.name = "AgentsClientError";
    this.kind = kind;
    this.code = details.code ?? null;
    this.retryAfterSeconds = kind === "rateLimit" ? (details.retryAfterSeconds ?? null) : null;
    this.httpStatus = details.httpStatus ?? null;
  }
}

export const isAgentsClientError = (value: unknown): value is AgentsClientError => value instanceof AgentsClientError;
