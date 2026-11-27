import { CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION, isAgentsClientError, readConversationRunStatusOfRunState, } from "../../contract/conversation";
/** Read interval while a turn runs (or right after a local send). */
export const POLLING_RUNNING_INTERVAL_MS = 2000;
/** Read interval while the conversation is idle or failed. */
export const POLLING_IDLE_INTERVAL_MS = 20000;
/** Ceiling of the exponential backoff after failed reads. */
export const POLLING_MAX_BACKOFF_MS = 60000;
/**
 * How long after a local send the poller keeps the running interval although the conversation still reads idle: the
 * server may store the owner's message before it marks the turn as running.
 */
export const POLLING_LOCAL_ACTIVITY_WINDOW_MS = 10000;
/** The global timer functions, looked up on each call (so a spec's fake timers installed later still apply). */
class GlobalPollingTimers {
    setTimeout = (callback, delayMs) => globalThis.setTimeout(callback, delayMs);
    clearTimeout = (timerHandle) => globalThis.clearTimeout(timerHandle);
    now = () => Date.now();
}
/** The browser document's `visibilityState` and `visibilitychange` event. */
class BrowserDocumentVisibility {
    browserDocument;
    constructor(browserDocument) {
        this.browserDocument = browserDocument;
    }
    isHidden = () => this.browserDocument.visibilityState === "hidden";
    listen = (listener) => {
        this.browserDocument.addEventListener("visibilitychange", listener);
        return this.browserDocument.removeEventListener.bind(this.browserDocument, "visibilitychange", listener);
    };
}
/**
 * The three-value run status a polled read reports: the stored run state, and "running" for an idle conversation the
 * server owes a turn (`agentTurnOwedAt`), as the chat's own derivation does.
 */
export const readPolledRunStatus = (conversation) => {
    const runStatus = readConversationRunStatusOfRunState(conversation.runState);
    return runStatus === "idle" && conversation.agentTurnOwedAt ? "running" : runStatus;
};
/** The end of a subscription a failed read means: a vanished conversation or lost access; null for a passing failure. */
const readSubscriptionEndReasonOfReadFailure = (failure) => {
    if (!isAgentsClientError(failure))
        return null;
    if (failure.kind === "notFound")
        return "gone";
    if (failure.kind === "forbidden")
        return "forbidden";
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
    conversationId;
    readMetadata;
    onSignal;
    onEnded;
    options;
    activePollers;
    isStopped = false;
    isReading = false;
    readAgainAfterCurrentRead = false;
    timerHandle = null;
    consecutiveFailedReads = 0;
    changeCounter = 0;
    summaryRevision = 0;
    previousChangeFingerprint = null;
    previousSummaryFingerprint = null;
    runStatus = null;
    hasConversationEnded = false;
    runningIntervalUntil = 0;
    stopListeningToVisibility = null;
    constructor(conversationId, readMetadata, onSignal, onEnded, options, activePollers) {
        this.conversationId = conversationId;
        this.readMetadata = readMetadata;
        this.onSignal = onSignal;
        this.onEnded = onEnded;
        this.options = options;
        this.activePollers = activePollers;
    }
    start = () => {
        this.activePollers.add(this);
        this.stopListeningToVisibility = this.options.documentVisibility?.listen(this.receiveVisibilityChange) ?? null;
        this.readNow();
    };
    /** The unsubscribe: no read, no timer and no signal after it; a read in flight is dropped. */
    stop = () => {
        this.isStopped = true;
        this.activePollers.delete(this);
        this.clearTimer();
        this.stopListeningToVisibility?.();
        this.stopListeningToVisibility = null;
    };
    receiveLocalActivity = () => {
        this.runningIntervalUntil = this.options.timers.now() + this.options.localActivityWindowMs;
        this.readNow();
    };
    /** Reads now; a read already in flight is followed by one more read instead (two reads never overlap). */
    readNow = () => {
        if (this.isStopped)
            return;
        if (this.isReading) {
            this.readAgainAfterCurrentRead = true;
            return;
        }
        this.clearTimer();
        this.isReading = true;
        void this.readMetadataOnce();
    };
    async readMetadataOnce() {
        let conversation;
        try {
            conversation = await this.readMetadata(this.conversationId);
        }
        catch (failure) {
            this.isReading = false;
            this.receiveReadFailure(failure);
            return;
        }
        this.isReading = false;
        if (this.isStopped)
            return;
        this.consecutiveFailedReads = 0;
        this.receiveConversation(conversation);
        this.continueAfterRead(this.readIntervalMs());
    }
    receiveConversation(conversation) {
        const runStatus = readPolledRunStatus(conversation);
        const reportsLastMessageId = conversation.lastMessageId !== undefined;
        const changeFingerprint = JSON.stringify([runStatus, conversation.lastMessageId ?? null, conversation.updated]);
        const summaryFingerprint = JSON.stringify([conversation.title, conversation.summary]);
        const isFirstRead = this.previousChangeFingerprint === null;
        const hasChanged = changeFingerprint !== this.previousChangeFingerprint || (!reportsLastMessageId && runStatus === "running");
        const hasSummaryChanged = !isFirstRead && summaryFingerprint !== this.previousSummaryFingerprint;
        if (!isFirstRead && hasChanged)
            this.changeCounter += 1;
        if (hasSummaryChanged)
            this.summaryRevision += 1;
        this.previousChangeFingerprint = changeFingerprint;
        this.previousSummaryFingerprint = summaryFingerprint;
        this.runStatus = runStatus;
        this.hasConversationEnded =
            conversation.runState !== null && CONVERSATION_RUN_STATES_THAT_END_THE_CONVERSATION.includes(conversation.runState);
        if (!isFirstRead && !hasChanged && !hasSummaryChanged)
            return;
        this.onSignal({
            changeCounter: this.changeCounter,
            lastMessageId: conversation.lastMessageId ?? null,
            runStatus,
            summaryUpdatedAt: this.summaryRevision,
        });
    }
    receiveReadFailure(failure) {
        if (this.isStopped)
            return;
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
    continueAfterRead(nextReadDelayMs) {
        if (this.readAgainAfterCurrentRead) {
            this.readAgainAfterCurrentRead = false;
            this.readNow();
            return;
        }
        // A completed or cancelled conversation changes no more: only a local send or the page's return reads it again.
        if (this.hasConversationEnded)
            return;
        this.scheduleRead(nextReadDelayMs);
    }
    readIntervalMs() {
        const isShortIntervalDue = this.runStatus === "running" || this.options.timers.now() < this.runningIntervalUntil;
        return isShortIntervalDue ? this.options.runningIntervalMs : this.options.idleIntervalMs;
    }
    /** Arms the next read — not while the page is hidden; its return reads at once. */
    scheduleRead(delayMs) {
        this.clearTimer();
        if (this.isStopped || this.options.documentVisibility?.isHidden())
            return;
        this.timerHandle = this.options.timers.setTimeout(this.readOnTimer, delayMs);
    }
    readOnTimer = () => {
        this.timerHandle = null;
        this.readNow();
    };
    receiveVisibilityChange = () => {
        if (this.isStopped)
            return;
        if (this.options.documentVisibility?.isHidden()) {
            this.clearTimer();
            return;
        }
        this.readNow();
    };
    clearTimer() {
        if (this.timerHandle === null)
            return;
        this.options.timers.clearTimeout(this.timerHandle);
        this.timerHandle = null;
    }
}
/** Every subscription of one polling default; `notifyLocalActivity` reaches the subscriptions of a conversation. */
class PollingConversationChangeSubscriptions {
    readMetadata;
    options;
    activePollers = new Set();
    constructor(readMetadata, options) {
        this.readMetadata = readMetadata;
        this.options = options;
    }
    subscribeToConversationChanges = (conversationId, onSignal, onEnded) => {
        const poller = new ConversationMetadataPoller(conversationId, this.readMetadata, onSignal, onEnded, this.options, this.activePollers);
        poller.start();
        return poller.stop;
    };
    notifyLocalActivity = (conversationId) => {
        for (const poller of [...this.activePollers]) {
            if (poller.conversationId === conversationId)
                poller.receiveLocalActivity();
        }
    };
}
const readDefaultDocumentVisibility = () => typeof document === "undefined" ? null : new BrowserDocumentVisibility(document);
/**
 * The polling default of `subscribeToConversationChanges` over a metadata read: every 2 s while a turn
 * runs, every 20 s while idle, at once after `notifyLocalActivity`; paused while the page is hidden and read once when
 * it shows again; exponential backoff on failed reads up to 60 s; a `notFound` / `forbidden` read ends the subscription
 * with `gone` / `forbidden`. One poller per subscription; see `ConversationMetadataPoller` for how a read becomes a
 * signal.
 */
export const createPollingConversationChangeSubscription = (readMetadata, options = {}) => new PollingConversationChangeSubscriptions(readMetadata, {
    runningIntervalMs: options.runningIntervalMs ?? POLLING_RUNNING_INTERVAL_MS,
    idleIntervalMs: options.idleIntervalMs ?? POLLING_IDLE_INTERVAL_MS,
    maxBackoffMs: options.maxBackoffMs ?? POLLING_MAX_BACKOFF_MS,
    localActivityWindowMs: options.localActivityWindowMs ?? POLLING_LOCAL_ACTIVITY_WINDOW_MS,
    timers: options.timers ?? new GlobalPollingTimers(),
    documentVisibility: options.documentVisibility === undefined ? readDefaultDocumentVisibility() : options.documentVisibility,
});
