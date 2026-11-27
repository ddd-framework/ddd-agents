import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createAccountRealtimePoll } from "../../ui/account-realtime";
import { AGENT_VOICE_RING_MESSAGE_TYPE } from "./agent-voice-ring";
import { AgentVoiceAccountRealtimeRingSource, createAgentVoiceRingSource } from "./agent-voice-ring-source";
const NOW_MS = 1767259000000;
const ringMessage = (callId, ringExpiresAtUnixMs, answerToken = `token-${callId}`) => ({
    messageType: AGENT_VOICE_RING_MESSAGE_TYPE,
    messageKey: String(callId),
    payloadJson: JSON.stringify({ callId, conversationId: 41, answerToken, ringExpiresAt: "", ringExpiresAtUnixMs, callerLabelKey: "", callerContext: {} }),
    secondsLeft: 30,
});
/** A poll that only records: who subscribed, who left, whether it was stopped; the spec delivers and clears. */
class FakeAccountRealtimePoll {
    subscribers = new Set();
    subscribeCount = 0;
    unsubscribeCount = 0;
    stopCount = 0;
    readNow = async () => 0;
    acceptArrivedMessage = () => true;
    readAfterPing = async () => false;
    subscribe = (subscriber) => {
        this.subscribeCount += 1;
        this.subscribers.add(subscriber);
        return () => {
            this.unsubscribeCount += 1;
            this.subscribers.delete(subscriber);
        };
    };
    stop = () => {
        this.stopCount += 1;
    };
    deliver(message) {
        this.subscribers.forEach(subscriber => subscriber.onMessage?.(message));
    }
    clear(message) {
        this.subscribers.forEach(subscriber => subscriber.onMessageCleared?.(message));
    }
}
const recordingSubscriber = () => {
    const subscriber = {
        rings: [],
        clearedCallIds: [],
        onRing: ring => subscriber.rings.push(ring),
        onRingCleared: callId => subscriber.clearedCallIds.push(callId),
    };
    return subscriber;
};
/** An account read that answers from the list, then with no message. */
const accountReadOf = (...reads) => async () => ({
    messages: reads.shift() ?? [],
    listenerCredential: null,
});
/** Lets the poll's first read answer (only `Date` is faked here). */
const settle = () => new Promise(resolve => setTimeout(resolve, 0));
describe("AgentVoiceAccountRealtimeRingSource, given the poll", () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ["Date"], now: NOW_MS });
    });
    afterEach(() => {
        vi.useRealTimers();
    });
    it("subscribes to the poll with the first subscriber and leaves it with the last, without stopping it", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = createAgentVoiceRingSource(poll);
        expect(ringSource).toBeInstanceOf(AgentVoiceAccountRealtimeRingSource);
        expect(poll.subscribeCount).toBe(0);
        const first = recordingSubscriber();
        const second = recordingSubscriber();
        const unsubscribeFirst = ringSource.subscribe(first.onRing, first.onRingCleared);
        const unsubscribeSecond = ringSource.subscribe(second.onRing, second.onRingCleared);
        expect(poll.subscribeCount).toBe(1);
        unsubscribeFirst();
        expect(poll.unsubscribeCount).toBe(0);
        unsubscribeSecond();
        expect(poll.unsubscribeCount).toBe(1);
        expect(poll.stopCount).toBe(0);
        ringSource.subscribe(first.onRing, first.onRingCleared);
        expect(poll.subscribeCount).toBe(2);
    });
    it("hands every ring message to every subscriber as a ring, and nothing else", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
        const first = recordingSubscriber();
        const second = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        ringSource.subscribe(second.onRing, second.onRingCleared);
        poll.deliver(ringMessage(8815, NOW_MS + 30000));
        poll.deliver({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 });
        expect(first.rings.map(ring => ring.callId)).toStrictEqual([8815]);
        expect(second.rings.map(ring => ring.callId)).toStrictEqual([8815]);
        expect(first.rings[0].answerToken).toBe("token-8815");
    });
    it("answers whether a message was a ring", () => {
        const ringSource = new AgentVoiceAccountRealtimeRingSource(new FakeAccountRealtimePoll());
        expect(ringSource.acceptRealtimeMessage(ringMessage(8815, NOW_MS + 30000))).toBe(true);
        expect(ringSource.acceptRealtimeMessage({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 })).toBe(false);
    });
    it("gives a later subscriber the rings it holds that are still inside their deadline, the newest payload per call", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
        const first = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        poll.deliver(ringMessage(8815, NOW_MS + 30000, "first-token"));
        poll.deliver(ringMessage(8815, NOW_MS + 60000, "replaced-token"));
        poll.deliver(ringMessage(8816, NOW_MS + 1000));
        vi.setSystemTime(NOW_MS + 1000);
        const later = recordingSubscriber();
        ringSource.subscribe(later.onRing, later.onRingCleared);
        expect(later.rings.map(ring => [ring.callId, ring.answerToken])).toStrictEqual([[8815, "replaced-token"]]);
        expect(poll.subscribeCount).toBe(1);
    });
    it("forgets its rings when the last subscriber leaves: a new subscriber gets only what the poll hands over again", async () => {
        const fakePoll = new FakeAccountRealtimePoll();
        const ringSourceOverFake = new AgentVoiceAccountRealtimeRingSource(fakePoll);
        const leaving = recordingSubscriber();
        const unsubscribe = ringSourceOverFake.subscribe(leaving.onRing, leaving.onRingCleared);
        fakePoll.deliver(ringMessage(8815, NOW_MS + 30000));
        unsubscribe();
        const coming = recordingSubscriber();
        ringSourceOverFake.subscribe(coming.onRing, coming.onRingCleared);
        expect(coming.rings).toStrictEqual([]);
        const poll = createAccountRealtimePoll({ readAccountRealtime: accountReadOf([ringMessage(8816, NOW_MS + 30000)]), isTransportBound: () => true });
        const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
        const before = recordingSubscriber();
        const unsubscribeBefore = ringSource.subscribe(before.onRing, before.onRingCleared);
        await settle();
        unsubscribeBefore();
        const after = recordingSubscriber();
        ringSource.subscribe(after.onRing, after.onRingCleared);
        poll.stop();
        expect(before.rings.map(ring => ring.callId)).toStrictEqual([8816]);
        expect(after.rings.map(ring => ring.callId)).toStrictEqual([8816]);
    });
    it("stops telling a subscriber that left", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
        const staying = recordingSubscriber();
        const leaving = recordingSubscriber();
        ringSource.subscribe(staying.onRing, staying.onRingCleared);
        const unsubscribeLeaving = ringSource.subscribe(leaving.onRing, leaving.onRingCleared);
        unsubscribeLeaving();
        poll.deliver(ringMessage(8815, NOW_MS + 30000));
        poll.clear(ringMessage(8815, NOW_MS + 30000));
        expect(staying.rings.length).toBe(1);
        expect(staying.clearedCallIds).toStrictEqual([8815]);
        expect(leaving.rings.length).toBe(0);
        expect(leaving.clearedCallIds).toStrictEqual([]);
    });
    it("hands over a ring the poll had open before the subscriber came", async () => {
        const poll = createAccountRealtimePoll({ readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30000)]), isTransportBound: () => true });
        await settle();
        const subscriber = recordingSubscriber();
        createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
        poll.stop();
        expect(subscriber.rings.map(ring => ring.callId)).toStrictEqual([8815]);
    });
    it("tells a ring the poll's read found gone as cleared, and hands it to no later subscriber", async () => {
        const poll = createAccountRealtimePoll({
            readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30000), ringMessage(8816, NOW_MS + 30000)], [ringMessage(8816, NOW_MS + 30000)]),
            isTransportBound: () => true,
        });
        const ringSource = createAgentVoiceRingSource(poll);
        const first = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        await settle();
        await poll.readNow();
        const later = recordingSubscriber();
        ringSource.subscribe(later.onRing, later.onRingCleared);
        poll.stop();
        expect(first.rings.map(ring => ring.callId)).toStrictEqual([8815, 8816]);
        expect(first.clearedCallIds).toStrictEqual([8815]);
        expect(later.rings.map(ring => ring.callId)).toStrictEqual([8816]);
        expect(later.clearedCallIds).toStrictEqual([]);
    });
    it("tells no subscriber the clear of a ring it never saw", async () => {
        const shortRing = { ...ringMessage(8816, NOW_MS + 5000), secondsLeft: 5 };
        const poll = createAccountRealtimePoll({
            readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30000), shortRing], []),
            isTransportBound: () => true,
        });
        await settle();
        vi.setSystemTime(NOW_MS + 10000);
        const ringSource = createAgentVoiceRingSource(poll);
        const subscriber = recordingSubscriber();
        ringSource.subscribe(subscriber.onRing, subscriber.onRingCleared);
        await poll.readNow();
        poll.stop();
        expect(subscriber.rings.map(ring => ring.callId)).toStrictEqual([8815]);
        expect(subscriber.clearedCallIds).toStrictEqual([8815]);
    });
    it("tells no later subscriber of the ring source the clear of a ring past its deadline when it came", async () => {
        const poll = createAccountRealtimePoll({
            readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 5000)], []),
            isTransportBound: () => true,
        });
        const ringSource = createAgentVoiceRingSource(poll);
        const first = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        await settle();
        vi.setSystemTime(NOW_MS + 10000);
        const later = recordingSubscriber();
        ringSource.subscribe(later.onRing, later.onRingCleared);
        await poll.readNow();
        poll.stop();
        expect(first.clearedCallIds).toStrictEqual([8815]);
        expect(later.rings).toStrictEqual([]);
        expect(later.clearedCallIds).toStrictEqual([]);
    });
    it("tells a later subscriber the clear of a ring it got in the hand-over", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = createAgentVoiceRingSource(poll);
        const first = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        poll.deliver(ringMessage(8815, NOW_MS + 30000));
        const later = recordingSubscriber();
        ringSource.subscribe(later.onRing, later.onRingCleared);
        poll.clear(ringMessage(8815, NOW_MS + 30000));
        expect(later.rings.map(ring => ring.callId)).toStrictEqual([8815]);
        expect(later.clearedCallIds).toStrictEqual([8815]);
    });
    it("tells a subscriber that came after the first ring's deadline the clear once it was handed the replacement", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = createAgentVoiceRingSource(poll);
        const first = recordingSubscriber();
        ringSource.subscribe(first.onRing, first.onRingCleared);
        poll.deliver(ringMessage(8815, NOW_MS + 5000, "first-token"));
        vi.setSystemTime(NOW_MS + 10000);
        const later = recordingSubscriber();
        ringSource.subscribe(later.onRing, later.onRingCleared);
        expect(later.rings).toStrictEqual([]);
        poll.deliver(ringMessage(8815, NOW_MS + 60000, "replaced-token"));
        poll.clear(ringMessage(8815, NOW_MS + 60000, "replaced-token"));
        expect(later.rings.map(ring => ring.answerToken)).toStrictEqual(["replaced-token"]);
        expect(first.clearedCallIds).toStrictEqual([8815]);
        expect(later.clearedCallIds).toStrictEqual([8815]);
    });
    it("tells nobody the clear of a call it holds no ring of, and still answers that the message was a ring", () => {
        const poll = new FakeAccountRealtimePoll();
        const ringSource = new AgentVoiceAccountRealtimeRingSource(poll);
        const subscriber = recordingSubscriber();
        ringSource.subscribe(subscriber.onRing, subscriber.onRingCleared);
        poll.deliver(ringMessage(8815, NOW_MS + 30000));
        expect(ringSource.acceptClearedRealtimeMessage(ringMessage(9999, NOW_MS + 30000))).toBe(true);
        expect(subscriber.clearedCallIds).toStrictEqual([]);
        expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30000))).toBe(true);
        expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30000))).toBe(true);
        expect(subscriber.clearedCallIds).toStrictEqual([8815]);
    });
    it("does not clear a replaced ring: the replacement arrives as a ring", async () => {
        const poll = createAccountRealtimePoll({
            readAccountRealtime: accountReadOf([ringMessage(8815, NOW_MS + 30000, "first-token")], [ringMessage(8815, NOW_MS + 60000, "replaced-token")]),
            isTransportBound: () => true,
        });
        const subscriber = recordingSubscriber();
        createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
        await settle();
        await poll.readNow();
        poll.stop();
        expect(subscriber.rings.map(ring => ring.answerToken)).toStrictEqual(["first-token", "replaced-token"]);
        expect(subscriber.clearedCallIds).toStrictEqual([]);
    });
    it("ignores a cleared message of another type", () => {
        const poll = new FakeAccountRealtimePoll();
        const subscriber = recordingSubscriber();
        createAgentVoiceRingSource(poll).subscribe(subscriber.onRing, subscriber.onRingCleared);
        poll.clear({ messageType: "agent.client.tool", messageKey: "8815", payloadJson: "{}", secondsLeft: 10 });
        expect(subscriber.clearedCallIds).toStrictEqual([]);
    });
});
describe("AgentVoiceAccountRealtimeRingSource.acceptClearedRealtimeMessage", () => {
    it("answers whether the cleared message was a ring", () => {
        const ringSource = new AgentVoiceAccountRealtimeRingSource(new FakeAccountRealtimePoll());
        expect(ringSource.acceptClearedRealtimeMessage(ringMessage(8815, NOW_MS + 30000))).toBe(true);
        expect(ringSource.acceptClearedRealtimeMessage({ messageType: "agent.client.tool", messageKey: "1", payloadJson: "{}", secondsLeft: 10 })).toBe(false);
    });
});
