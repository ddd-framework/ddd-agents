import { readAgentVoiceRingOfRealtimeMessage } from "./agent-voice-ring";
/**
 * The ring source over the account realtime poll: every message of the type `agent.voice.ring` the poll delivers goes
 * to the subscribers as a ring, and a ring message the poll reports as cleared (a read found it gone: answered or
 * declined on another client, canceled, past its deadline) as a cleared ring. A subscriber is told `onRingCleared`
 * only for a ring it was handed, as an arrival or in the hand-over when it subscribed.
 *
 * It listens while somebody is subscribed: it subscribes to the poll with the first subscriber and leaves it with the
 * last. The poll stays the application's, which creates and stops it; the ring source never stops it.
 */
export class AgentVoiceAccountRealtimeRingSource {
    accountRealtimePoll;
    subscribers = new Set();
    /** The rings delivered while it listens, by call: a subscriber that comes later still gets the ones in their deadline. */
    knownRingsByCallId = new Map();
    /** Set while it listens to the poll. */
    unsubscribeFromAccountRealtimePoll;
    constructor(accountRealtimePoll) {
        this.accountRealtimePoll = accountRealtimePoll;
    }
    subscribe(onRing, onRingCleared) {
        const subscriber = { onRing, onRingCleared };
        this.subscribers.add(subscriber);
        const nowMs = Date.now();
        this.knownRingsByCallId.forEach((knownRing) => {
            if (knownRing.ring.ringExpiresAtUnixMs > nowMs)
                this.handRing(knownRing, subscriber);
        });
        if (this.unsubscribeFromAccountRealtimePoll === undefined)
            this.listenToAccountRealtimePoll();
        return () => {
            this.subscribers.delete(subscriber);
            if (this.subscribers.size === 0)
                this.leaveAccountRealtimePoll();
        };
    }
    /** Takes one message of the account's realtime messages; returns whether it was a ring. */
    acceptRealtimeMessage(message) {
        const ring = readAgentVoiceRingOfRealtimeMessage(message);
        if (ring === null)
            return false;
        const knownRing = { ring, handedTo: new Set() };
        this.knownRingsByCallId.set(ring.callId, knownRing);
        this.subscribers.forEach((subscriber) => this.handRing(knownRing, subscriber));
        return true;
    }
    /**
     * Takes one message the account's realtime messages hold no more; returns whether it was a ring. The clear goes to
     * the subscribers the ring of that call was handed to, and to nobody for a call this source holds no ring of.
     */
    acceptClearedRealtimeMessage(message) {
        const ring = readAgentVoiceRingOfRealtimeMessage(message);
        if (ring === null)
            return false;
        const knownRing = this.knownRingsByCallId.get(ring.callId);
        this.knownRingsByCallId.delete(ring.callId);
        this.subscribers.forEach((subscriber) => {
            if (knownRing?.handedTo.has(subscriber))
                subscriber.onRingCleared(ring.callId);
        });
        return true;
    }
    /** Hands a ring to a subscriber and notes it on the known ring. */
    handRing(knownRing, subscriber) {
        knownRing.handedTo.add(subscriber);
        subscriber.onRing(knownRing.ring);
    }
    /** The poll hands over the messages open now, then every arrival and the clear of every message it handed over. */
    listenToAccountRealtimePoll() {
        this.unsubscribeFromAccountRealtimePoll = this.accountRealtimePoll.subscribe({
            onMessage: (message) => {
                this.acceptRealtimeMessage(message);
            },
            onMessageCleared: (message) => {
                this.acceptClearedRealtimeMessage(message);
            },
        });
    }
    leaveAccountRealtimePoll() {
        this.unsubscribeFromAccountRealtimePoll?.();
        this.unsubscribeFromAccountRealtimePoll = undefined;
        this.knownRingsByCallId.clear();
    }
}
/**
 * The ring source over the account realtime poll of `./ui`:
 * `createAgentVoiceRingSource(createAccountRealtimePoll({ readAccountRealtime, … }))`. An application with its own
 * ring transport passes its own `AgentVoiceRingSource` instead.
 */
export const createAgentVoiceRingSource = (accountRealtimePoll) => new AgentVoiceAccountRealtimeRingSource(accountRealtimePoll);
