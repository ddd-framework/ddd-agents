import {
  type AgentsConversation,
  type ConversationChangeSignal,
  type ConversationRunStatus,
  type ConversationSubscriptionEndReason,
  CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION,
  isAgentsClientError,
  readConversationRunStatusOfRunState,
} from "../../contract/conversation";

/** Read interval while a turn runs (or right after a local send). */
export const POLLING_RUNNING_INTERVAL_MS = 2_000;
/** Read interval while the conversation is idle or failed. */
export const POLLING_IDLE_INTERVAL_MS = 20_000;
/** Ceiling of the exponential backoff after failed reads. */
export const POLLING_MAX_BACKOFF_MS = 60_000;
/**
 * How long after a local send the poller keeps the running interval although the conversation still reads idle: the
 * server may store the owner's message before it marks the turn as running.
 */
export const POLLING_LOCAL_ACTIVITY_WINDOW_MS = 10_000;

/** Reads a conversation's metadata — the port's `getConversationMetadata`. */
export type ConversationMetadataReader = (conversationId: number) => Promise<AgentsConversation>;

/** The timer functions the poller uses; a spec hands in fake ones. */
export interface PollingTimers {
  setTimeout: (callback: () => void, delayMs: number) => unknown;
  clearTimeout: (timerHandle: unknown) => void;
  now: () => number;
}

/** Whether the page is hidden, and a way to hear when that changes; a spec hands in its own. */
export interface PollingDocumentVisibility {
  isHidden: () => boolean;
  /** Calls `listener` on every visibility change; returns the function that stops it. */
  listen: (listener: () => void) => () => void;
}

export interface PollingConversationChangeSubscriptionOptions {
  /** Default `POLLING_RUNNING_INTERVAL_MS` (2 s). */
  runningIntervalMs?: number;
  /** Default `POLLING_IDLE_INTERVAL_MS` (20 s). */
  idleIntervalMs?: number;
  /** Default `POLLING_MAX_BACKOFF_MS` (60 s). */
  maxBackoffMs?: number;
  /** Default `POLLING_LOCAL_ACTIVITY_WINDOW_MS` (10 s). */
  localActivityWindowMs?: number;
  /** Default: the global `setTimeout` / `clearTimeout` and `Date.now`. */
  timers?: PollingTimers;
  /** Default: the browser `document`; `null` = the page counts as always visible (no `document`, a worker). */
  documentVisibility?: PollingDocumentVisibility | null;
}

/**
 * The polling default of the live updates: the port's `subscribeToConversationChanges` plus
 * `notifyLocalActivity`, which `withPollingChangeSubscription` calls after every send the server accepted.
 */
export interface PollingConversationChangeSubscription {
  subscribeToConversationChanges: (
    conversationId: number,
    onSignal: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ) => () => void;
  /** Reads the conversation's metadata now and keeps the running interval for the local-activity window. */
  notifyLocalActivity: (conversationId: number) => void;
}

interface ResolvedPollingOptions {
  runningIntervalMs: number;
  idleIntervalMs: number;
  maxBackoffMs: number;
  localActivityWindowMs: number;
  timers: PollingTimers;
  documentVisibility: PollingDocumentVisibility | null;
}

/** The global timer functions, looked up on each call (so a spec's fake timers installed later still apply). */
class GlobalPollingTimers implements PollingTimers {
  setTimeout = (callback: () => void, delayMs: number): unknown => globalThis.setTimeout(callback, delayMs);

  clearTimeout = (timerHandle: unknown): void => globalThis.clearTimeout(timerHandle as ReturnType<typeof setTimeout>);

  now = (): number => Date.now();
}

/** The browser document's `visibilityState` and `visibilitychange` event. */
class BrowserDocumentVisibility implements PollingDocumentVisibility {
  constructor(protected readonly browserDocument: Document) {}

  isHidden = (): boolean => this.browserDocument.visibilityState === "hidden";

  listen = (listener: () => void): (() => void) => {
    this.browserDocument.addEventListener("visibilitychange", listener);
    return this.browserDocument.removeEventListener.bind(this.browserDocument, "visibilitychange", listener);
  };
}

/**
 * The three-value run status a polled read reports: the stored run state, and "running" for an idle conversation the
 * server owes a turn (`agentTurnOwedAt`), as the chat's own derivation does.
 */
export const readPolledRunStatus = (conversation: AgentsConversation): ConversationRunStatus | null => {
  const runStatus = readConversationRunStatusOfRunState(conversation.runState);
  return runStatus === "idle" && conversation.agentTurnOwedAt ? "running" : runStatus;
};

/** The end of a subscription a failed read means: a vanished conversation or lost access; null for a passing failure. */
const readSubscriptionEndReasonOfReadFailure = (failure: unknown): ConversationSubscriptionEndReason | null => {
  if (!isAgentsClientError(failure)) return null;
  if (failure.kind === "notFound") return "gone";
  if (failure.kind === "forbidden") return "forbidden";
  return null;
};

/**
 * One subscription: reads the conversation's metadata on a timer and turns each read into the change signal.
 *
 * Numbering (the signal leaves it open; the metadata carries no server change counter): the poller keeps its own counter. It
 * starts at 0 with the first read and advances by one whenever a read differs from the previous one in the run status,
 * `lastMessageId` or `updated` — so every polled signal is an ordinary change signal (no `isStatusCheck`) and the
 * package rules apply unchanged: an advance reads the changed tail (throttled), a terminal status reads at once,
 * re-reads the metadata and converges on `lastMessageId`. A status check would carry only the run status as news and
 * would miss a new message under an unchanged status. Without `lastMessageId` in the metadata every poll of a running
 * turn counts as a change (the changed tail is read every poll). `summaryUpdatedAt` is a second local counter that
 * advances when `title` or `summary` changed. A read with no change emits nothing.
 */
class ConversationMetadataPoller {
  protected isStopped = false;
  protected isReading = false;
  protected readAgainAfterCurrentRead = false;
  protected timerHandle: unknown = null;
  protected consecutiveFailedReads = 0;
  protected changeCounter = 0;
  protected summaryRevision = 0;
  protected previousChangeFingerprint: string | null = null;
  protected previousSummaryFingerprint: string | null = null;
  protected runStatus: ConversationRunStatus | null = null;
  protected hasConversationEnded = false;
  protected runningIntervalUntil = 0;
  protected stopListeningToVisibility: (() => void) | null = null;

  constructor(
    readonly conversationId: number,
    protected readonly readMetadata: ConversationMetadataReader,
    protected readonly onSignal: (signal: ConversationChangeSignal) => void,
    protected readonly onEnded: (reason: ConversationSubscriptionEndReason) => void,
    protected readonly options: ResolvedPollingOptions,
    protected readonly activePollers: Set<ConversationMetadataPoller>,
  ) {}

  start = (): void => {
    this.activePollers.add(this);
    this.stopListeningToVisibility = this.options.documentVisibility?.listen(this.receiveVisibilityChange) ?? null;
    this.readNow();
  };

  /** The unsubscribe: no read, no timer and no signal after it; a read in flight is dropped. */
  stop = (): void => {
    this.isStopped = true;
    this.activePollers.delete(this);
    this.clearTimer();
    this.stopListeningToVisibility?.();
    this.stopListeningToVisibility = null;
  };

  receiveLocalActivity = (): void => {
    this.runningIntervalUntil = this.options.timers.now() + this.options.localActivityWindowMs;
    this.readNow();
  };

  /** Reads now; a read already in flight is followed by one more read instead (two reads never overlap). */
  protected readNow = (): void => {
    if (this.isStopped) return;
    if (this.isReading) {
      this.readAgainAfterCurrentRead = true;
      return;
    }
    this.clearTimer();
    this.isReading = true;
    void this.readMetadataOnce();
  };

  protected async readMetadataOnce(): Promise<void> {
    let conversation: AgentsConversation;
    try {
      conversation = await this.readMetadata(this.conversationId);
    } catch (failure) {
      this.isReading = false;
      this.receiveReadFailure(failure);
      return;
    }
    this.isReading = false;
    if (this.isStopped) return;
    this.consecutiveFailedReads = 0;
    this.receiveConversation(conversation);
    this.continueAfterRead(this.readIntervalMs());
  }

  protected receiveConversation(conversation: AgentsConversation): void {
    const runStatus = readPolledRunStatus(conversation);
    const reportsLastMessageId = conversation.lastMessageId !== undefined;
    const changeFingerprint = JSON.stringify([runStatus, conversation.lastMessageId ?? null, conversation.updated]);
    const summaryFingerprint = JSON.stringify([conversation.title, conversation.summary]);
    const isFirstRead = this.previousChangeFingerprint === null;
    const hasChanged =
      changeFingerprint !== this.previousChangeFingerprint || (!reportsLastMessageId && runStatus === "running");
    const hasSummaryChanged = !isFirstRead && summaryFingerprint !== this.previousSummaryFingerprint;
    if (!isFirstRead && hasChanged) this.changeCounter += 1;
    if (hasSummaryChanged) this.summaryRevision += 1;
    this.previousChangeFingerprint = changeFingerprint;
    this.previousSummaryFingerprint = summaryFingerprint;
    this.runStatus = runStatus;
    this.hasConversationEnded =
      conversation.runState !== null && CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION.includes(conversation.runState);
    if (!isFirstRead && !hasChanged && !hasSummaryChanged) return;
    this.onSignal({
      changeCounter: this.changeCounter,
      lastMessageId: conversation.lastMessageId ?? null,
      runStatus,
      summaryUpdatedAt: this.summaryRevision,
    });
  }

  protected receiveReadFailure(failure: unknown): void {
    if (this.isStopped) return;
    const endReason = readSubscriptionEndReasonOfReadFailure(failure);
    if (endReason !== null) {
      this.stop();
      this.onEnded(endReason);
      return;
    }
    this.consecutiveFailedReads += 1;
    const backoffMs = this.readIntervalMs() * 2 ** this.consecutiveFailedReads;
    this.continueAfterRead(Math.min(backoffMs, this.options.maxBackoffMs));
  }

  protected continueAfterRead(nextReadDelayMs: number): void {
    if (this.readAgainAfterCurrentRead) {
      this.readAgainAfterCurrentRead = false;
      this.readNow();
      return;
    }
    // A completed or cancelled conversation changes no more: only a local send or the page's return reads it again.
    if (this.hasConversationEnded) return;
    this.scheduleRead(nextReadDelayMs);
  }

  protected readIntervalMs(): number {
    const isShortIntervalDue = this.runStatus === "running" || this.options.timers.now() < this.runningIntervalUntil;
    return isShortIntervalDue ? this.options.runningIntervalMs : this.options.idleIntervalMs;
  }

  /** Arms the next read — not while the page is hidden; its return reads at once. */
  protected scheduleRead(delayMs: number): void {
    this.clearTimer();
    if (this.isStopped || this.options.documentVisibility?.isHidden()) return;
    this.timerHandle = this.options.timers.setTimeout(this.readOnTimer, delayMs);
  }

  protected readOnTimer = (): void => {
    this.timerHandle = null;
    this.readNow();
  };

  protected receiveVisibilityChange = (): void => {
    if (this.isStopped) return;
    if (this.options.documentVisibility?.isHidden()) {
      this.clearTimer();
      return;
    }
    this.readNow();
  };

  protected clearTimer(): void {
    if (this.timerHandle === null) return;
    this.options.timers.clearTimeout(this.timerHandle);
    this.timerHandle = null;
  }
}

/** Every subscription of one polling default; `notifyLocalActivity` reaches the subscriptions of a conversation. */
class PollingConversationChangeSubscriptions implements PollingConversationChangeSubscription {
  protected readonly activePollers = new Set<ConversationMetadataPoller>();

  constructor(
    protected readonly readMetadata: ConversationMetadataReader,
    protected readonly options: ResolvedPollingOptions,
  ) {}

  subscribeToConversationChanges = (
    conversationId: number,
    onSignal: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ): (() => void) => {
    const poller = new ConversationMetadataPoller(
      conversationId,
      this.readMetadata,
      onSignal,
      onEnded,
      this.options,
      this.activePollers,
    );
    poller.start();
    return poller.stop;
  };

  notifyLocalActivity = (conversationId: number): void => {
    for (const poller of [...this.activePollers]) {
      if (poller.conversationId === conversationId) poller.receiveLocalActivity();
    }
  };
}

const readDefaultDocumentVisibility = (): PollingDocumentVisibility | null =>
  typeof document === "undefined" ? null : new BrowserDocumentVisibility(document);

/**
 * The polling default of `subscribeToConversationChanges` over a metadata read: every 2 s while a turn
 * runs, every 20 s while idle, at once after `notifyLocalActivity`; paused while the page is hidden and read once when
 * it shows again; exponential backoff on failed reads up to 60 s; a `notFound` / `forbidden` read ends the subscription
 * with `gone` / `forbidden`. One poller per subscription; see `ConversationMetadataPoller` for how a read becomes a
 * signal.
 */
export const createPollingConversationChangeSubscription = (
  readMetadata: ConversationMetadataReader,
  options: PollingConversationChangeSubscriptionOptions = {},
): PollingConversationChangeSubscription =>
  new PollingConversationChangeSubscriptions(readMetadata, {
    runningIntervalMs: options.runningIntervalMs ?? POLLING_RUNNING_INTERVAL_MS,
    idleIntervalMs: options.idleIntervalMs ?? POLLING_IDLE_INTERVAL_MS,
    maxBackoffMs: options.maxBackoffMs ?? POLLING_MAX_BACKOFF_MS,
    localActivityWindowMs: options.localActivityWindowMs ?? POLLING_LOCAL_ACTIVITY_WINDOW_MS,
    timers: options.timers ?? new GlobalPollingTimers(),
    documentVisibility: options.documentVisibility === undefined ? readDefaultDocumentVisibility() : options.documentVisibility,
  });
