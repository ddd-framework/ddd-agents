/** While a turn streams, per-message signals arrive in bursts — the re-read is coalesced to one per this window. */
export const LIVE_READ_THROTTLE_MS = 1200;
/** Grace before the first convergence re-read (lets the terminal status' own immediate read land first). */
export const CONVERGENCE_BASE_DELAY_MS = 700;
/** Backoff ceiling between convergence re-reads. */
export const CONVERGENCE_MAX_DELAY_MS = 2500;
/** Stop re-reading for a missing terminal `lastMessageId` after this many attempts (the next action recovers). */
export const CONVERGENCE_MAX_ATTEMPTS = 6;
const isTerminalRunStatus = (runStatus) => runStatus === "idle" || runStatus === "failed";
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
    reads;
    lastChangeCounter = null;
    hasSummaryBaseline = false;
    lastSummaryUpdatedAt = null;
    hasPushedSignalArrived = false;
    lastStatusCheckRunStatus = null;
    lastReadAt = 0;
    trailingReadTimer = null;
    convergenceTimer = null;
    convergenceAttempt = 0;
    convergenceMessageId = null;
    disposed = false;
    constructor(reads) {
        this.reads = reads;
    }
    receiveSignal = (signal) => {
        if (this.disposed)
            return;
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
        }
        else if (signal.summaryUpdatedAt !== null && signal.summaryUpdatedAt !== this.lastSummaryUpdatedAt) {
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
        if (!hasCounterAdvanced && !isTerminal)
            return;
        this.lastChangeCounter = signal.changeCounter;
        this.requestRead(isTerminal);
        if (isTerminal) {
            this.convergeOnMessage(signal.lastMessageId);
            if (hasCounterAdvanced)
                this.reads.readConversationMetadata();
        }
    };
    receiveEnd = (_reason) => {
        if (this.disposed)
            return;
        this.reads.showRunStatus(null);
    };
    /** Reads now, bypassing the throttle (the chat's safety poll). */
    readNow = () => {
        if (this.disposed)
            return;
        this.reads.readChangedMessages();
    };
    dispose = () => {
        this.disposed = true;
        this.clearTrailingRead();
        this.clearConvergence();
    };
    receiveStatusCheck(runStatus, isPushPathAlive) {
        if (runStatus === null)
            return;
        this.reads.showRunStatus(runStatus);
        if (!isPushPathAlive && runStatus !== this.lastStatusCheckRunStatus) {
            this.requestRead(isTerminalRunStatus(runStatus));
        }
        this.lastStatusCheckRunStatus = runStatus;
    }
    /** One coordinated read path: `immediate` (a terminal status) cancels a pending trailing read and reads now. */
    requestRead(immediate) {
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
    readThrottled() {
        this.lastReadAt = Date.now();
        this.reads.readChangedMessages();
    }
    readTrailing = () => {
        this.trailingReadTimer = null;
        if (this.disposed)
            return;
        this.readThrottled();
    };
    clearTrailingRead() {
        if (this.trailingReadTimer !== null) {
            clearTimeout(this.trailingReadTimer);
            this.trailingReadTimer = null;
        }
    }
    convergeOnMessage(messageId) {
        this.clearConvergence();
        if (messageId === null)
            return;
        this.convergenceMessageId = messageId;
        this.convergenceAttempt = 0;
        this.convergenceTimer = setTimeout(this.checkConvergence, CONVERGENCE_BASE_DELAY_MS);
    }
    checkConvergence = () => {
        this.convergenceTimer = null;
        const messageId = this.convergenceMessageId;
        if (this.disposed || messageId === null)
            return;
        if (this.reads.hasLoadedMessage(messageId))
            return;
        if (this.convergenceAttempt >= CONVERGENCE_MAX_ATTEMPTS)
            return;
        this.convergenceAttempt += 1;
        this.reads.readChangedMessages();
        this.convergenceTimer = setTimeout(this.checkConvergence, Math.min(CONVERGENCE_BASE_DELAY_MS * this.convergenceAttempt, CONVERGENCE_MAX_DELAY_MS));
    };
    clearConvergence() {
        if (this.convergenceTimer !== null) {
            clearTimeout(this.convergenceTimer);
            this.convergenceTimer = null;
        }
    }
}
