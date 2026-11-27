/** The pause between two account reads while no transport is bound. */
export const ACCOUNT_REALTIME_POLL_INTERVAL_MS = 5000;
/** The pause before the one re-read of `readAfterPing` when the first read brought nothing new. */
export const ACCOUNT_REALTIME_PING_REREAD_DELAY_MS = 400;
/** What makes two deliveries the same message: a new payload under the same type and key is a new message. */
const identityOf = (message) => `${message.messageType}\u0000${message.messageKey}\u0000${message.payloadJson}`;
/** Where a message stands on the realtime port: a replacement stands at the same place. */
const placeOf = (message) => `${message.messageType}\u0000${message.messageKey}`;
/**
 * The account-level realtime poll of a client that shows no conversation: reads the account at once, then every
 * interval while no transport is bound, hands each new message to `onMessage` once (the same message from the poll and
 * the transport arrives once) and each message a read finds gone to `onMessageCleared` once. Further subscribers come
 * and go through `subscribe`. The client of a conversation keeps its run-state read; this is the read without one.
 */
export const createAccountRealtimePoll = (options) => {
    const intervalMs = options.intervalMs ?? ACCOUNT_REALTIME_POLL_INTERVAL_MS;
    const rereadDelayMs = options.rereadDelayMs ?? ACCOUNT_REALTIME_PING_REREAD_DELAY_MS;
    /**
     * The messages the last read saw (and the transport delivered since), by identity: one gone from a read may arrive
     * again. A read reports as cleared only what was known when it started.
     */
    let knownMessages = new Map();
    /** The hooks of the options first, then the subscribers in the order they came. */
    const subscriptions = new Set([{ subscriber: options }]);
    let startedReadCount = 0;
    let newestAnsweredRead = 0;
    let stopped = false;
    let timer = null;
    const knownMessageOf = (message, nowMs) => ({
        message,
        startedReadCount,
        expiresAtMs: nowMs + message.secondsLeft * 1000,
        handedTo: new Set(),
    });
    /** Hands a message to a subscriber and notes it on the message, also for a subscriber without the hook. */
    const handMessage = (knownMessage, subscription) => {
        knownMessage.handedTo.add(subscription);
        subscription.subscriber.onMessage?.(knownMessage.message);
    };
    /**
     * A subscriber that came during the hand-over already got the open messages; one that left gets nothing more. Each
     * cleared place comes with its gone messages, the oldest first: a subscriber is told the newest one it was handed,
     * and nothing when it was handed none.
     */
    const handToSubscribers = (arrivedMessages, goneMessagesOfClearedPlaces) => {
        const subscriptionsNow = [...subscriptions];
        for (const arrivedMessage of arrivedMessages) {
            for (const subscription of subscriptionsNow) {
                if (subscriptions.has(subscription))
                    handMessage(arrivedMessage, subscription);
            }
        }
        for (const goneMessages of goneMessagesOfClearedPlaces) {
            for (const subscription of subscriptionsNow) {
                if (!subscriptions.has(subscription))
                    continue;
                const handedMessage = goneMessages.filter(goneMessage => goneMessage.handedTo.has(subscription)).pop();
                if (handedMessage !== undefined)
                    subscription.subscriber.onMessageCleared?.(handedMessage.message);
            }
        }
    };
    const acceptArrivedMessage = (message) => {
        const identity = identityOf(message);
        if (knownMessages.has(identity))
            return false;
        const knownMessage = knownMessageOf(message, Date.now());
        knownMessages.set(identity, knownMessage);
        handToSubscribers([knownMessage], []);
        return true;
    };
    const readNow = async () => {
        startedReadCount += 1;
        const readNumber = startedReadCount;
        const read = await options.readAccountRealtime(options.clientId);
        // the answer of a read started later is already in: this one tells an older state
        if (readNumber < newestAnsweredRead)
            return 0;
        newestAnsweredRead = readNumber;
        const nowMs = Date.now();
        const knownAfterRead = new Map();
        const openPlaces = new Set();
        const arrivedMessages = [];
        for (const message of read.messages) {
            const identity = identityOf(message);
            const knownMessage = knownMessages.get(identity) ?? knownMessageOf(message, nowMs);
            if (!knownMessages.has(identity))
                arrivedMessages.push(knownMessage);
            knownAfterRead.set(identity, knownMessage);
            openPlaces.add(placeOf(message));
        }
        const goneMessages = [];
        for (const [identity, knownMessage] of knownMessages) {
            if (knownAfterRead.has(identity))
                continue;
            if (knownMessage.startedReadCount >= readNumber) {
                // it became known after this read had started (from the transport, or from a read answered in between):
                // this read cannot tell that it is gone
                knownAfterRead.set(identity, knownMessage);
                openPlaces.add(placeOf(knownMessage.message));
            }
            else {
                goneMessages.push(knownMessage);
            }
        }
        /** By place: a replaced message is no clear, and the gone messages of one place are one clear. */
        const goneMessagesByClearedPlace = new Map();
        for (const goneMessage of goneMessages) {
            const place = placeOf(goneMessage.message);
            if (openPlaces.has(place))
                continue;
            goneMessagesByClearedPlace.set(place, [...(goneMessagesByClearedPlace.get(place) ?? []), goneMessage]);
        }
        knownMessages = knownAfterRead;
        handToSubscribers(arrivedMessages, [...goneMessagesByClearedPlace.values()]);
        options.onListenerCredential?.(read.listenerCredential);
        return arrivedMessages.length;
    };
    const subscribe = (subscriber) => {
        const subscription = { subscriber };
        subscriptions.add(subscription);
        const nowMs = Date.now();
        for (const knownMessage of [...knownMessages.values()]) {
            if (knownMessage.expiresAtMs > nowMs)
                handMessage(knownMessage, subscription);
        }
        return () => {
            subscriptions.delete(subscription);
        };
    };
    const schedule = () => {
        if (stopped)
            return;
        timer = setTimeout(tick, intervalMs);
    };
    const readIgnoringFailure = async () => {
        try {
            return await readNow();
        }
        catch (error) {
            options.onReadFailed?.(error);
            return 0;
        }
    };
    const tick = async () => {
        timer = null;
        if (stopped)
            return;
        if (!(options.isTransportBound?.() ?? false))
            await readIgnoringFailure();
        schedule();
    };
    const readAfterPing = async () => {
        if ((await readIgnoringFailure()) > 0)
            return true;
        await new Promise(resolve => setTimeout(resolve, rereadDelayMs));
        return (await readIgnoringFailure()) > 0;
    };
    // the first read always happens: it carries the credential the host binds its transport with
    void readIgnoringFailure().then(schedule);
    return {
        readNow,
        acceptArrivedMessage,
        readAfterPing,
        subscribe,
        stop: () => {
            stopped = true;
            if (timer !== null)
                clearTimeout(timer);
            timer = null;
        },
    };
};
