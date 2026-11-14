import type { AgentsConversationClient, AgentsConversationOperation } from "../AgentsConversationClient";
import {
  AgentsClientError,
  type AgentsConversation,
  type AgentsConversationMessage,
  type ChangedConversationMessages,
  type ChangedMessagesRequest,
  type ConversationActivity,
  type ConversationAttachmentReference,
  type ConversationChangeSignal,
  type ConversationListQueryOptions,
  type ConversationMessageSearchHit,
  type ConversationMessageSentPayload,
  type ConversationMessagesPage,
  type ConversationRunStatus,
  CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION,
  type ConversationSearchGroup,
  type ConversationSearchOptions,
  type ConversationSignal,
  type ConversationSubscriptionEndReason,
  type PostedMessage,
  readConversationRunStatusOfRunState,
  type SelectableModel,
  type StartedConversation,
} from "../../contract/conversation";

/** One recorded conversation handed to the in-memory client: plain data, as a fixture file holds it. */
export interface RecordedConversation {
  conversation: AgentsConversation;
  messages: AgentsConversationMessage[];
  /** What `getConversationActivity` answers for this conversation; an empty, finished turn when omitted. */
  activity?: ConversationActivity;
}

export interface InMemoryAgentsConversationClientData {
  conversations: RecordedConversation[];
  /**
   * The assistant's answers to a post, an injected signal, a start or a retry, used in order; the last one repeats.
   * Default: one answer, "Done.".
   */
  scriptedAssistantAnswers?: string[];
  /** What `getSelectableModels` answers; the client has no `getSelectableModels` when omitted. */
  selectableModels?: SelectableModel[];
  /** The page size of `getChangedMessages` when the request names none. Default 50. */
  defaultPageSize?: number;
  /** What `getMessageSentPayload` answers, by message id; the client has no `getMessageSentPayload` when omitted. */
  sentPayloadsByMessageId?: Record<number, ConversationMessageSentPayload>;
  /**
   * The assistant answers this many milliseconds after the send, like a server's turn: the run state is RUNNING
   * meanwhile, then the answer is stored and the run state goes back to IDLE — no `emit()` involved, so a poller sees it.
   * Default 0: the answer is stored together with the send.
   */
  assistantAnswerDelayMs?: number;
  /**
   * The client has no `subscribeToConversationChanges` — a backend without push. The chat then follows the
   * conversation through the polling default (`withPollingChangeSubscription`). Default false.
   */
  withoutChangeSubscription?: boolean;
}

/** A subscriber of one conversation's live updates. */
interface ConversationChangeSubscriber {
  onChange: (signal: ConversationChangeSignal) => void;
  onEnded: (reason: ConversationSubscriptionEndReason) => void;
}

/** The live-update state of one conversation. */
interface ConversationSignalState {
  changeCounter: number;
  summaryUpdatedAt: number | null;
}

const DEFAULT_SCRIPTED_ASSISTANT_ANSWER = "Done.";
const DEFAULT_PAGE_SIZE = 50;

/** A deep copy, so a caller can never change the client's records through a returned object. */
const copyOf = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

const sortByIndexAscending = (messages: AgentsConversationMessage[]): AgentsConversationMessage[] =>
  [...messages].sort((first, second) => (first.indexInConversation ?? 0) - (second.indexInConversation ?? 0));

const textContains = (text: string | null | undefined, searchText: string): boolean =>
  (text ?? "").toLowerCase().includes(searchText.trim().toLowerCase());

/** The message text a send stores: the typed text plus one reference line per attachment (as the host application's chat sends it). */
const buildStoredMessageText = (text: string, attachments: ConversationAttachmentReference[] | undefined): string =>
  attachments?.length
    ? `${text}\n\n${attachments
        .map((attachment) => `[Attached file: ${attachment.fileName ?? "file"} — ${attachment.publicUrl ?? ""}]`)
        .join("\n")}`
    : text;

/**
 * The conversation client over recorded conversations, for specs, stories and the reference app
 * (`./testing`). Behaves like the server where the chat can see it: a post stores the owner's message and, at once,
 * the next scripted assistant answer — but live updates are driven by hand: `emit()` sends the conversation's next
 * signal to its subscribers (counter advanced, newest message id, the run status), `end()` ends the subscriptions.
 * With `assistantAnswerDelayMs` the answer is stored later, as a server turn would (RUNNING meanwhile); with
 * `withoutChangeSubscription` the client has no subscription at all and the chat polls it.
 */
export class InMemoryAgentsConversationClient implements AgentsConversationClient {
  protected readonly recordsByConversationId = new Map<number, RecordedConversation>();
  protected readonly subscribersByConversationId = new Map<number, Set<ConversationChangeSubscriber>>();
  protected readonly signalStateByConversationId = new Map<number, ConversationSignalState>();
  protected readonly scriptedAssistantAnswers: string[];
  protected readonly defaultPageSize: number;
  protected readonly selectableModels: SelectableModel[] | undefined;
  protected readonly sentPayloadsByMessageId: Record<number, ConversationMessageSentPayload> | undefined;
  protected readonly assistantAnswerDelayMs: number;
  protected readonly requestCountsByOperation = new Map<AgentsConversationOperation, number>();
  protected scriptedAssistantAnswersUsed = 0;
  protected nextMessageId: number;
  protected nextConversationId: number;

  /** Present only when the data names selectable models (the port function is optional). */
  readonly getSelectableModels?: () => Promise<SelectableModel[]>;
  /** Present only when the data names sent payloads (the port function is optional). */
  readonly getMessageSentPayload?: (conversationId: number, messageId: number) => Promise<ConversationMessageSentPayload>;
  /** Present unless the data asks for a client without push (`withoutChangeSubscription`). */
  readonly subscribeToConversationChanges?: (
    conversationId: number,
    onChange: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ) => () => void;

  constructor(data: InMemoryAgentsConversationClientData) {
    for (const recordedConversation of copyOf(data.conversations)) {
      this.recordsByConversationId.set(recordedConversation.conversation.id, recordedConversation);
    }
    const scriptedAssistantAnswers = data.scriptedAssistantAnswers ?? [];
    this.scriptedAssistantAnswers =
      scriptedAssistantAnswers.length > 0 ? [...scriptedAssistantAnswers] : [DEFAULT_SCRIPTED_ASSISTANT_ANSWER];
    this.defaultPageSize = data.defaultPageSize ?? DEFAULT_PAGE_SIZE;
    this.selectableModels = data.selectableModels ? copyOf(data.selectableModels) : undefined;
    if (this.selectableModels) {
      this.getSelectableModels = this.readSelectableModels;
    }
    this.sentPayloadsByMessageId = data.sentPayloadsByMessageId ? copyOf(data.sentPayloadsByMessageId) : undefined;
    if (this.sentPayloadsByMessageId) {
      this.getMessageSentPayload = this.readMessageSentPayload;
    }
    this.assistantAnswerDelayMs = data.assistantAnswerDelayMs ?? 0;
    if (!data.withoutChangeSubscription) {
      this.subscribeToConversationChanges = this.subscribeToRecordedConversationChanges;
    }
    const allMessageIds = data.conversations.flatMap((recorded) => recorded.messages.map((message) => message.id));
    const allConversationIds = data.conversations.map((recorded) => recorded.conversation.id);
    this.nextMessageId = Math.max(0, ...allMessageIds) + 1;
    this.nextConversationId = Math.max(0, ...allConversationIds) + 1;
  }

  // ── Port ──────────────────────────────────────────────────────────────────────────────────────────────────────

  listConversations = async (
    agentSpaceKey: number,
    options: ConversationListQueryOptions = {},
  ): Promise<AgentsConversation[]> => {
    this.countRequest("listConversations");
    const { includeUnsummarized = false, paging = {} } = options;
    const { top, skip = 0 } = paging;
    const conversationsOfAgentSpace = [...this.recordsByConversationId.values()]
      .map((recorded) => recorded.conversation)
      .filter((conversation) => conversation.agentSpaceKey === agentSpaceKey)
      .filter((conversation) => includeUnsummarized || conversation.title !== null)
      .sort((first, second) => second.id - first.id);
    const end = top === undefined ? undefined : skip + top;
    return copyOf(conversationsOfAgentSpace.slice(skip, end));
  };

  /** Account-wide, like the server: every recorded conversation is searched. */
  searchConversations = async (text: string, options: ConversationSearchOptions = {}): Promise<ConversationSearchGroup[]> => {
    this.countRequest("searchConversations");
    const searchGroups: ConversationSearchGroup[] = [];
    for (const recorded of this.recordsByConversationId.values()) {
      const hits = this.findMessageSearchHits(recorded, text);
      if (hits.length === 0) continue;
      searchGroups.push({
        conversationId: recorded.conversation.id,
        title: recorded.conversation.title,
        summary: null,
        created: recorded.messages[0]?.createdDate ?? null,
        updated: recorded.messages[recorded.messages.length - 1]?.createdDate ?? null,
        relevance: 1,
        hits,
      });
    }
    return copyOf(searchGroups.slice(0, options.limit));
  };

  /** The stored conversation with `lastMessageId` (the newest stored message), as a backend without push answers it. */
  getConversationMetadata = async (conversationId: number): Promise<AgentsConversation> => {
    this.countRequest("getConversationMetadata");
    const recorded = this.readRecord(conversationId);
    return copyOf({ ...recorded.conversation, lastMessageId: sortByIndexAscending(recorded.messages).pop()?.id ?? null });
  };

  getNewestMessagesPage = async (conversationId: number, pageSize: number): Promise<ConversationMessagesPage> => {
    this.countRequest("getNewestMessagesPage");
    const newestFirst = this.readMessagesNewestFirst(conversationId);
    return copyOf({ messages: newestFirst.slice(0, pageSize), hasMoreOlder: newestFirst.length > pageSize });
  };

  getOlderMessagesPage = async (
    conversationId: number,
    beforeIndex: number,
    pageSize: number,
  ): Promise<ConversationMessagesPage> => {
    this.countRequest("getOlderMessagesPage");
    const olderMessages = this.readMessagesNewestFirst(conversationId).filter(
      (message) => (message.indexInConversation ?? Number.MAX_SAFE_INTEGER) < beforeIndex,
    );
    return copyOf({ messages: olderMessages.slice(0, pageSize), hasMoreOlder: olderMessages.length > pageSize });
  };

  getChangedMessages = async (
    conversationId: number,
    request: ChangedMessagesRequest,
  ): Promise<ChangedConversationMessages> => {
    this.countRequest("getChangedMessages");
    const changedMessages = this.readMessagesNewestFirst(conversationId).filter(
      (message) => message.id > request.highestKnownId || (message.indexInConversation ?? -1) >= request.fromIndex,
    );
    return copyOf({ messages: changedMessages.slice(0, request.pageSize ?? this.defaultPageSize) });
  };

  searchConversationMessages = async (
    conversationId: number,
    text: string,
    options: ConversationSearchOptions = {},
  ): Promise<ConversationMessageSearchHit[]> => {
    this.countRequest("searchConversationMessages");
    return copyOf(this.findMessageSearchHits(this.readRecord(conversationId), text).slice(0, options.limit));
  };

  getConversationActivity = async (conversationId: number): Promise<ConversationActivity> => {
    this.countRequest("getConversationActivity");
    return copyOf(this.readRecord(conversationId).activity ?? { turnMessageId: null, turnRunning: false, elements: [] });
  };

  startConversation = async (request: {
    agentSpaceKey: number;
    text: string;
    startSignalText?: string;
    entryPointKey?: string;
    model?: string;
    attachments?: ConversationAttachmentReference[];
  }): Promise<StartedConversation> => {
    this.countRequest("startConversation");
    if (request.text === "" && !request.startSignalText) {
      throw new AgentsClientError("other", { httpStatus: 400, message: "A start needs a message or a signal." });
    }
    const reopenedConversation = request.entryPointKey
      ? [...this.recordsByConversationId.values()].find(
          (recorded) =>
            recorded.conversation.agentSpaceKey === request.agentSpaceKey &&
            recorded.conversation.entryPointKey === request.entryPointKey,
        )
      : undefined;
    if (reopenedConversation) {
      return { conversation: copyOf(reopenedConversation.conversation), messages: [], reopenedExisting: true };
    }
    const recorded: RecordedConversation = {
      conversation: {
        id: this.nextConversationId,
        agentSpaceKey: request.agentSpaceKey,
        runState: "IDLE",
        agentTurnOwedAt: null,
        title: null,
        titleManuallyEdited: false,
        summary: null,
        created: null,
        updated: null,
        purposeName: null,
        initiatorType: null,
        scheduleId: null,
        entryPointKey: request.entryPointKey ?? null,
        progressSteps: [],
      },
      messages: [],
    };
    this.nextConversationId += 1;
    this.recordsByConversationId.set(recorded.conversation.id, recorded);
    if (request.startSignalText) {
      this.appendMessage(recorded, { role: "USER", messageType: "SIGNAL", authorType: "USER", message: request.startSignalText });
    }
    if (request.text !== "") {
      this.appendMessage(recorded, {
        role: "USER",
        messageType: "TEXT",
        authorType: "USER",
        message: buildStoredMessageText(request.text, request.attachments),
      });
    }
    const messagesBeforeTheAnswer = copyOf(recorded.messages);
    this.appendScriptedAssistantAnswer(recorded);
    return { conversation: copyOf(recorded.conversation), messages: messagesBeforeTheAnswer, reopenedExisting: false };
  };

  postMessage = async (
    conversationId: number,
    message: { text: string; model?: string; attachments?: ConversationAttachmentReference[] },
  ): Promise<PostedMessage> => {
    this.countRequest("postMessage");
    const recorded = this.readOpenRecord(conversationId);
    this.appendMessage(recorded, {
      role: "USER",
      messageType: "TEXT",
      authorType: "USER",
      message: buildStoredMessageText(message.text, message.attachments),
    });
    const messagesBeforeTheAnswer = copyOf(recorded.messages);
    this.appendScriptedAssistantAnswer(recorded);
    return { messages: messagesBeforeTheAnswer };
  };

  injectSignal = async (conversationId: number, signal: ConversationSignal): Promise<void> => {
    this.countRequest("injectSignal");
    const recorded = this.readOpenRecord(conversationId);
    this.appendMessage(recorded, {
      role: "USER",
      messageType: "SIGNAL",
      authorType: "USER",
      message: signal.text,
      signalSource: signal.source ?? "FRONTEND_WIDGET_CLICK",
    });
    this.appendScriptedAssistantAnswer(recorded);
  };

  retryConversation = async (conversationId: number): Promise<void> => {
    this.countRequest("retryConversation");
    const recorded = this.readRecord(conversationId);
    const newestMessage = sortByIndexAscending(recorded.messages).pop();
    if (newestMessage === undefined || newestMessage.role === "ASSISTANT") {
      throw new AgentsClientError("other", { httpStatus: 400, message: "Nothing to retry." });
    }
    this.appendScriptedAssistantAnswer(recorded);
  };

  renameConversation = async (conversationId: number, title: string): Promise<AgentsConversation> => {
    this.countRequest("renameConversation");
    const recorded = this.readRecord(conversationId);
    recorded.conversation.title = title;
    recorded.conversation.titleManuallyEdited = true;
    return copyOf(recorded.conversation);
  };

  deleteConversation = async (conversationId: number): Promise<void> => {
    this.countRequest("deleteConversation");
    this.readRecord(conversationId);
    this.recordsByConversationId.delete(conversationId);
  };

  /** The port's `subscribeToConversationChanges` unless `withoutChangeSubscription`; signals come from `emit()`. */
  protected subscribeToRecordedConversationChanges = (
    conversationId: number,
    onChange: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ): (() => void) => {
    const subscriber: ConversationChangeSubscriber = { onChange, onEnded };
    const subscribers = this.subscribersByConversationId.get(conversationId) ?? new Set<ConversationChangeSubscriber>();
    subscribers.add(subscriber);
    this.subscribersByConversationId.set(conversationId, subscribers);
    // Like the realtime database: the current value arrives at once, before any change.
    const record = this.recordsByConversationId.get(conversationId);
    if (record) {
      onChange(this.buildSignal(record, this.readSignalState(conversationId), null));
    }
    return () => {
      subscribers.delete(subscriber);
    };
  };

  // ── Driving live updates by hand ──────────────────────────────────────────────────────────────────────────────

  /**
   * Sends the conversation's next signal to its subscribers: the counter advances, `lastMessageId` is the newest
   * stored message, the run status follows the stored run state unless `runStatus` is given. `summaryChanged` also
   * advances `summaryUpdatedAt`. Returns the sent signal.
   */
  emit = (
    conversationId: number,
    change: { runStatus?: ConversationRunStatus; summaryChanged?: boolean } = {},
  ): ConversationChangeSignal => {
    const record = this.readRecord(conversationId);
    const signalState = this.readSignalState(conversationId);
    signalState.changeCounter += 1;
    if (change.summaryChanged) {
      signalState.summaryUpdatedAt = (signalState.summaryUpdatedAt ?? 0) + 1;
    }
    const signal = this.buildSignal(record, signalState, change.runStatus ?? null);
    for (const subscriber of [...(this.subscribersByConversationId.get(conversationId) ?? [])]) {
      subscriber.onChange(copyOf(signal));
    }
    return signal;
  };

  /** Ends every subscription of the conversation with `reason`, as the server's deletion or a lost access would. */
  end = (conversationId: number, reason: ConversationSubscriptionEndReason): void => {
    const subscribers = [...(this.subscribersByConversationId.get(conversationId) ?? [])];
    this.subscribersByConversationId.delete(conversationId);
    for (const subscriber of subscribers) {
      subscriber.onEnded(reason);
    }
  };

  /** Sets a conversation's stored run state (a turn that runs, fails, or a conversation that closes). */
  setRunState = (conversationId: number, runState: AgentsConversation["runState"]): void => {
    this.readRecord(conversationId).conversation.runState = runState;
  };

  /**
   * How many requests the client answered — of one port function, or of all when `operation` is omitted (the
   * subscription is no request). For assertions such as "no read after the chat unmounted".
   */
  readRequestCount = (operation?: AgentsConversationOperation): number => {
    if (operation !== undefined) return this.requestCountsByOperation.get(operation) ?? 0;
    let requestCount = 0;
    for (const operationRequestCount of this.requestCountsByOperation.values()) requestCount += operationRequestCount;
    return requestCount;
  };

  /** Every stored message of a conversation in index order — for assertions. */
  readStoredMessages = (conversationId: number): AgentsConversationMessage[] =>
    copyOf(sortByIndexAscending(this.readRecord(conversationId).messages));

  // ── Internals ─────────────────────────────────────────────────────────────────────────────────────────────────

  protected countRequest(operation: AgentsConversationOperation): void {
    this.requestCountsByOperation.set(operation, (this.requestCountsByOperation.get(operation) ?? 0) + 1);
  }

  protected readSelectableModels = async (): Promise<SelectableModel[]> => {
    this.countRequest("getSelectableModels");
    return copyOf(this.selectableModels ?? []);
  };

  protected readMessageSentPayload = async (
    conversationId: number,
    messageId: number,
  ): Promise<ConversationMessageSentPayload> => {
    this.countRequest("getMessageSentPayload");
    this.readRecord(conversationId);
    const sentPayload = this.sentPayloadsByMessageId?.[messageId];
    if (sentPayload === undefined) {
      throw new AgentsClientError("notFound", { httpStatus: 404, message: `No sent payload for message ${messageId}.` });
    }
    return copyOf(sentPayload);
  };

  protected readMessagesNewestFirst(conversationId: number): AgentsConversationMessage[] {
    return sortByIndexAscending(this.readRecord(conversationId).messages).reverse();
  }

  protected readRecord(conversationId: number): RecordedConversation {
    const recorded = this.recordsByConversationId.get(conversationId);
    if (!recorded) {
      throw new AgentsClientError("notFound", { httpStatus: 404, message: `Conversation ${conversationId} not found.` });
    }
    return recorded;
  }

  /** The record of a conversation that still takes input; a completed or cancelled one refuses like the server. */
  protected readOpenRecord(conversationId: number): RecordedConversation {
    const recorded = this.readRecord(conversationId);
    const runState = recorded.conversation.runState;
    if (runState !== null && CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION.includes(runState)) {
      throw new AgentsClientError("closed", { httpStatus: 400, code: "CONVERSATION_CLOSED" });
    }
    return recorded;
  }

  protected readSignalState(conversationId: number): ConversationSignalState {
    const existingSignalState = this.signalStateByConversationId.get(conversationId);
    if (existingSignalState) return existingSignalState;
    const signalState: ConversationSignalState = { changeCounter: 0, summaryUpdatedAt: null };
    this.signalStateByConversationId.set(conversationId, signalState);
    return signalState;
  }

  protected buildSignal(
    recorded: RecordedConversation,
    signalState: ConversationSignalState,
    runStatus: ConversationRunStatus | null,
  ): ConversationChangeSignal {
    const newestMessage = sortByIndexAscending(recorded.messages).pop();
    return {
      changeCounter: signalState.changeCounter,
      lastMessageId: newestMessage?.id ?? null,
      runStatus: runStatus ?? readConversationRunStatusOfRunState(recorded.conversation.runState),
      summaryUpdatedAt: signalState.summaryUpdatedAt,
    };
  }

  protected appendMessage(
    recorded: RecordedConversation,
    fields: Pick<AgentsConversationMessage, "role" | "messageType" | "authorType" | "message"> &
      Partial<Pick<AgentsConversationMessage, "signalSource">>,
  ): AgentsConversationMessage {
    const highestIndex = Math.max(-1, ...recorded.messages.map((message) => message.indexInConversation ?? -1));
    const storedMessage: AgentsConversationMessage = {
      id: this.nextMessageId,
      conversationId: recorded.conversation.id,
      indexInConversation: highestIndex + 1,
      status: "COMPLETED",
      signalSource: null,
      createdDate: null,
      toolCall: null,
      delegatedConversations: [],
      subAgent: null,
      ...fields,
    };
    this.nextMessageId += 1;
    recorded.messages.push(storedMessage);
    return storedMessage;
  }

  /** The next scripted answer: stored at once, or after `assistantAnswerDelayMs` with the run state RUNNING meanwhile. */
  protected appendScriptedAssistantAnswer(recorded: RecordedConversation): void {
    const answerIndex = Math.min(this.scriptedAssistantAnswersUsed, this.scriptedAssistantAnswers.length - 1);
    this.scriptedAssistantAnswersUsed += 1;
    const answerText = this.scriptedAssistantAnswers[answerIndex];
    if (this.assistantAnswerDelayMs <= 0) {
      this.storeAssistantAnswer(recorded, answerText);
      return;
    }
    recorded.conversation.runState = "RUNNING";
    setTimeout(this.storeAssistantAnswer, this.assistantAnswerDelayMs, recorded, answerText);
  }

  protected storeAssistantAnswer = (recorded: RecordedConversation, answerText: string): void => {
    this.appendMessage(recorded, {
      role: "ASSISTANT",
      messageType: "TEXT",
      authorType: "ASSISTANT",
      message: answerText,
    });
    recorded.conversation.runState = "IDLE";
  };

  protected findMessageSearchHits(recorded: RecordedConversation, text: string): ConversationMessageSearchHit[] {
    if (text.trim() === "") return [];
    return sortByIndexAscending(recorded.messages)
      .filter((message) => message.messageType === "TEXT" && textContains(message.message, text))
      .map((message) => ({
        messageId: message.id,
        conversationId: recorded.conversation.id,
        indexInConversation: message.indexInConversation,
        role: message.role,
        excerpt: message.message,
        relevance: 1,
        created: message.createdDate,
        updated: null,
        title: recorded.conversation.title,
      }));
  }
}

/** The in-memory conversation client over recorded conversations (plain data). */
export const createInMemoryAgentsConversationClient = (
  data: InMemoryAgentsConversationClientData,
): InMemoryAgentsConversationClient => new InMemoryAgentsConversationClient(data);
