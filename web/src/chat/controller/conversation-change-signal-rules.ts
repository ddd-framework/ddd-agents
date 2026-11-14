import type {
  ConversationChangeSignal,
  ConversationRunStatus,
  ConversationSubscriptionEndReason,
} from "../../contract/conversation";

/** While a turn streams, per-message signals arrive in bursts — the re-read is coalesced to one per this window. */
export const LIVE_READ_THROTTLE_MS = 1200;
/** Grace before the first convergence re-read (lets the terminal status' own immediate read land first). */
export const CONVERGENCE_BASE_DELAY_MS = 700;
/** Backoff ceiling between convergence re-reads. */
export const CONVERGENCE_MAX_DELAY_MS = 2500;
/** Stop re-reading for a missing terminal `lastMessageId` after this many attempts (the next action recovers). */
export const CONVERGENCE_MAX_ATTEMPTS = 6;

/** What the live-update rules make the chat do. */
export interface ConversationLiveUpdateReads {
  /** Read the rows that changed (the chat's changed-tail read). */
  readChangedMessages: () => void;
  /** Read the conversation's metadata again (title, run state). */
  readConversationMetadata: () => void;
  /** Whether a message is loaded — the convergence check of a terminal signal's `lastMessageId`. */
  hasLoadedMessage: (messageId: number) => boolean;
  /** The run status the chat shows; null once the subscription ended. */
  showRunStatus: (runStatus: ConversationRunStatus | null) => void;
}

const isTerminalRunStatus = (runStatus: ConversationRunStatus | null): boolean =>
  runStatus === "idle" || runStatus === "failed";

/**
 * The package's rules over a conversation's live updates — one instance per subscription:
 * - the first pushed signal reads once (a turn may have moved between the chat's open and the subscription), and is
 *   the baseline of the counter and of `summaryUpdatedAt`;
 * - a counter advance reads again, throttled to one read per `LIVE_READ_THROTTLE_MS` with one trailing read;
 * - a terminal status reads at once, re-reads the metadata (on the baseline and on every terminal signal that
 *   advances the counter: the server closes a conversation after the turn and publishes one more idle signal), and
 *   keeps reading with backoff until the signal's `lastMessageId` is loaded (a reply that beat its own commit);
 * - a changed `summaryUpdatedAt` after the baseline re-reads the metadata only;
 * - a status-check emission (`isStatusCheck`) sets the status, and reads again — at once on a terminal status — only
 *   while the push path is not alive (the binding's `isPushPathAlive`; without it: no pushed signal arrived yet) and
 *   its status differs from the previous check's;
 * - an ended subscription clears the status.
 * The transport's health (reconnect, a dead path, token refresh) is the binding's.
 */
export class ConversationChangeSignalRules {
  protected lastChangeCounter: number | null = null;
  protected hasSummaryBaseline = false;
  protected lastSummaryUpdatedAt: number | null = null;
  protected hasPushedSignalArrived = false;
  protected lastStatusCheckRunStatus: ConversationRunStatus | null = null;
  protected lastReadAt = 0;
  protected trailingReadTimer: ReturnType<typeof setTimeout> | null = null;
  protected convergenceTimer: ReturnType<typeof setTimeout> | null = null;
  protected convergenceAttempt = 0;
  protected convergenceMessageId: number | null = null;
  protected disposed = false;

  constructor(protected readonly reads: ConversationLiveUpdateReads) {}

  receiveSignal = (signal: ConversationChangeSignal): void => {
    if (this.disposed) return;
    if (signal.isStatusCheck) {
      this.receiveStatusCheck(signal.runStatus, signal.isPushPathAlive ?? this.hasPushedSignalArrived);
      return;
    }
    this.hasPushedSignalArrived = true;
    if (signal.runStatus !== null) {
      this.reads.showRunStatus(signal.runStatus);
    }
    if (!this.hasSummaryBaseline) {
      this.hasSummaryBaseline = true;
      this.lastSummaryUpdatedAt = signal.summaryUpdatedAt;
    } else if (signal.summaryUpdatedAt !== null && signal.summaryUpdatedAt !== this.lastSummaryUpdatedAt) {
      this.lastSummaryUpdatedAt = signal.summaryUpdatedAt;
      this.reads.readConversationMetadata();
    }
    const isTerminal = isTerminalRunStatus(signal.runStatus);
    if (this.lastChangeCounter === null) {
      this.lastChangeCounter = signal.changeCounter;
      this.requestRead(isTerminal);
      if (isTerminal) {
        this.convergeOnMessage(signal.lastMessageId);
        this.reads.readConversationMetadata();
      }
      return;
    }
    const hasCounterAdvanced = signal.changeCounter !== this.lastChangeCounter;
    if (!hasCounterAdvanced && !isTerminal) return;
    this.lastChangeCounter = signal.changeCounter;
    this.requestRead(isTerminal);
    if (isTerminal) {
      this.convergeOnMessage(signal.lastMessageId);
      if (hasCounterAdvanced) this.reads.readConversationMetadata();
    }
  };

  receiveEnd = (_reason: ConversationSubscriptionEndReason): void => {
    if (this.disposed) return;
    this.reads.showRunStatus(null);
  };

  /** Reads now, bypassing the throttle (the chat's safety poll). */
  readNow = (): void => {
    if (this.disposed) return;
    this.reads.readChangedMessages();
  };

  dispose = (): void => {
    this.disposed = true;
    this.clearTrailingRead();
    this.clearConvergence();
  };

  protected receiveStatusCheck(runStatus: ConversationRunStatus | null, isPushPathAlive: boolean): void {
    if (runStatus === null) return;
    this.reads.showRunStatus(runStatus);
    if (!isPushPathAlive && runStatus !== this.lastStatusCheckRunStatus) {
      this.requestRead(isTerminalRunStatus(runStatus));
    }
    this.lastStatusCheckRunStatus = runStatus;
  }

  /** One coordinated read path: `immediate` (a terminal status) cancels a pending trailing read and reads now. */
  protected requestRead(immediate: boolean): void {
    if (immediate) {
      this.clearTrailingRead();
      this.readThrottled();
      return;
    }
    const sinceLastRead = Date.now() - this.lastReadAt;
    if (sinceLastRead >= LIVE_READ_THROTTLE_MS) {
      this.readThrottled();
      return;
    }
    if (this.trailingReadTimer === null) {
      this.trailingReadTimer = setTimeout(this.readTrailing, LIVE_READ_THROTTLE_MS - sinceLastRead);
    }
  }

  protected readThrottled(): void {
    this.lastReadAt = Date.now();
    this.reads.readChangedMessages();
  }

  protected readTrailing = (): void => {
    this.trailingReadTimer = null;
    if (this.disposed) return;
    this.readThrottled();
  };

  protected clearTrailingRead(): void {
    if (this.trailingReadTimer !== null) {
      clearTimeout(this.trailingReadTimer);
      this.trailingReadTimer = null;
    }
  }

  protected convergeOnMessage(messageId: number | null): void {
    this.clearConvergence();
    if (messageId === null) return;
    this.convergenceMessageId = messageId;
    this.convergenceAttempt = 0;
    this.convergenceTimer = setTimeout(this.checkConvergence, CONVERGENCE_BASE_DELAY_MS);
  }

  protected checkConvergence = (): void => {
    this.convergenceTimer = null;
    const messageId = this.convergenceMessageId;
    if (this.disposed || messageId === null) return;
    if (this.reads.hasLoadedMessage(messageId)) return;
    if (this.convergenceAttempt >= CONVERGENCE_MAX_ATTEMPTS) return;
    this.convergenceAttempt += 1;
    this.reads.readChangedMessages();
    this.convergenceTimer = setTimeout(
      this.checkConvergence,
      Math.min(CONVERGENCE_BASE_DELAY_MS * this.convergenceAttempt, CONVERGENCE_MAX_DELAY_MS),
    );
  };

  protected clearConvergence(): void {
    if (this.convergenceTimer !== null) {
      clearTimeout(this.convergenceTimer);
      this.convergenceTimer = null;
    }
  }
}
