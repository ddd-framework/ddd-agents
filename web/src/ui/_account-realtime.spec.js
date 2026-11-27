import { afterEach, describe, expect, test, vi } from "vitest";
import { ACCOUNT_REALTIME_PING_REREAD_DELAY_MS, createAccountRealtimePoll, } from "./account-realtime";
/**
 * The account-level realtime poll of a client that shows no conversation: a first read at once, a read every interval
 * while no transport is bound, none while one is; the arrival hook gets each message once (from the poll and from the
 * transport), a replacement and a message sent again after it was gone arrive again; a ping that overtook the write
 * reads once more.
 */
const ring = (messageKey, payloadJson = "{}") => ({
    messageType: "demo.voice.ring",
    messageKey,
    payloadJson,
    secondsLeft: 30,
});
const readOf = (...messages) => ({ messages, listenerCredential: null });
afterEach(() => {
    vi.useRealTimers();
});
describe("createAccountRealtimePoll", () => {
    test("reads at once and every interval while no transport is bound, never while one is", async () => {
        vi.useFakeTimers();
        let transportBound = false;
        const readAccountRealtime = vi.fn(async () => readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, onMessage: () => undefined, isTransportBound: () => transportBound, intervalMs: 1000 });
        await vi.advanceTimersByTimeAsync(0);
        expect(readAccountRealtime).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(2);
        transportBound = true;
        await vi.advanceTimersByTimeAsync(3000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(2);
        transportBound = false;
        await vi.advanceTimersByTimeAsync(1000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(3);
        poll.stop();
        await vi.advanceTimersByTimeAsync(5000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(3);
    });
    test("the arrival hook gets each message once, a replacement and a re-sent message again", async () => {
        vi.useFakeTimers();
        const reads = [readOf(ring("call-1")), readOf(ring("call-1")), readOf(ring("call-1", '{"n":2}')), readOf(), readOf(ring("call-1"))];
        const arrived = [];
        const credentials = [];
        const poll = createAccountRealtimePoll({
            readAccountRealtime: async () => reads.shift() ?? readOf(),
            onMessage: message => arrived.push(`${message.messageKey}${message.payloadJson}`),
            onListenerCredential: credential => credentials.push(credential),
            intervalMs: 1000,
        });
        await vi.advanceTimersByTimeAsync(4000);
        poll.stop();
        expect(arrived).toEqual(["call-1{}", 'call-1{"n":2}', "call-1{}"]);
        expect(credentials).toEqual([null, null, null, null, null]);
    });
    test("a message the transport delivered does not arrive again from the poll", async () => {
        vi.useFakeTimers();
        const onMessage = vi.fn();
        const reads = [readOf(), readOf(ring("call-1"))];
        const poll = createAccountRealtimePoll({ readAccountRealtime: async () => reads.shift() ?? readOf(ring("call-1")), onMessage, intervalMs: 1000 });
        await vi.advanceTimersByTimeAsync(0);
        expect(poll.acceptArrivedMessage(ring("call-1"))).toBe(true);
        expect(poll.acceptArrivedMessage(ring("call-1"))).toBe(false);
        await vi.advanceTimersByTimeAsync(1000);
        poll.stop();
        expect(onMessage).toHaveBeenCalledTimes(1);
    });
    test("a ping whose read brought nothing reads exactly once more after the delay", async () => {
        vi.useFakeTimers();
        const reads = [readOf(), readOf(), readOf(ring("call-1"))];
        const readAccountRealtime = vi.fn(async () => reads.shift() ?? readOf(ring("call-1")));
        const onMessage = vi.fn();
        const poll = createAccountRealtimePoll({ readAccountRealtime, onMessage, isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        expect(readAccountRealtime).toHaveBeenCalledTimes(1);
        const arrived = poll.readAfterPing();
        await vi.advanceTimersByTimeAsync(ACCOUNT_REALTIME_PING_REREAD_DELAY_MS - 1);
        expect(readAccountRealtime).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        await expect(arrived).resolves.toBe(true);
        expect(readAccountRealtime).toHaveBeenCalledTimes(3);
        expect(onMessage).toHaveBeenCalledTimes(1);
        poll.stop();
    });
    test("a failed read is reported and the next interval reads again", async () => {
        vi.useFakeTimers();
        const onReadFailed = vi.fn();
        let readCount = 0;
        const poll = createAccountRealtimePoll({
            readAccountRealtime: async () => {
                readCount += 1;
                if (readCount === 1)
                    throw new Error("offline");
                return readOf();
            },
            onMessage: () => undefined,
            onReadFailed,
            intervalMs: 1000,
        });
        await vi.advanceTimersByTimeAsync(1000);
        poll.stop();
        expect(onReadFailed).toHaveBeenCalledTimes(1);
        expect(readCount).toBe(2);
    });
});
/** An account read that answers from `answers` (an empty list answers no message) or, for `null`, holds the answer. */
const scriptedAccountRead = (...answers) => {
    const heldReads = [];
    const readAccountRealtime = vi.fn(() => new Promise((resolve, reject) => {
        const answer = answers.length > 0 ? answers.shift() : readOf();
        if (answer === null)
            heldReads.push({ answer: resolve, fail: reject });
        else
            resolve(answer);
    }));
    return { readAccountRealtime, heldReads };
};
/** Every call of the hooks it is handed, in order, as `<who> <hook> <key><payload>`. */
const callLog = () => {
    const calls = [];
    const subscriberNamed = (who) => ({
        onMessage: (message) => calls.push(`${who} message ${message.messageKey}${message.payloadJson}`),
        onMessageCleared: (message) => calls.push(`${who} cleared ${message.messageKey}${message.payloadJson}`),
    });
    return { calls, subscriberNamed };
};
const credentialOf = (credential) => ({ transportName: "fake", credential, listenPath: "accounts/3" });
describe("createAccountRealtimePoll: clears and subscribers", () => {
    test("the first answer hands every message to onMessage, clears nothing, then hands the credential", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead({ messages: [ring("call-1"), ring("call-2")], listenerCredential: credentialOf("c-1") });
        const poll = createAccountRealtimePoll({
            readAccountRealtime,
            ...subscriberNamed("options"),
            onListenerCredential: credential => calls.push(`credential ${credential?.credential}`),
            isTransportBound: () => true,
        });
        await vi.advanceTimersByTimeAsync(0);
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", "options message call-2{}", "credential c-1"]);
    });
    test("a message arrives once; a new payload under its type and key arrives again and is no clear", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(ring("call-1")), readOf(ring("call-1", '{"n":2}')));
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        await expect(poll.readNow()).resolves.toBe(0);
        await expect(poll.readNow()).resolves.toBe(1);
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", 'options message call-1{"n":2}']);
    });
    test("a read without the message's type and key clears it once, as it arrived last, after that read's arrivals; sent again it arrives again", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(ring("call-1", '{"n":2}')), readOf(ring("call-2")), readOf(ring("call-2")), readOf(ring("call-1"), ring("call-2")));
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        for (let read = 0; read < 4; read += 1)
            await poll.readNow();
        poll.stop();
        expect(calls).toEqual([
            "options message call-1{}",
            'options message call-1{"n":2}',
            "options message call-2{}",
            'options cleared call-1{"n":2}',
            "options message call-1{}",
        ]);
    });
    test("only a read that started after a message became known can clear it: one the transport delivered during a read is cleared by the next", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime, heldReads } = scriptedAccountRead(readOf(), null, readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        const readInFlight = poll.readNow();
        expect(poll.acceptArrivedMessage(ring("call-1"))).toBe(true);
        heldReads[0].answer(readOf());
        await readInFlight;
        expect(calls).toEqual(["options message call-1{}"]);
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", "options cleared call-1{}"]);
    });
    test("a later subscriber gets the open messages inside their secondsLeft before subscribe returns, then every arrival and the clears of what it was handed, after the creation-time hooks", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const shortRing = { ...ring("call-2"), secondsLeft: 5 };
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1"), shortRing), readOf(ring("call-3")));
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(10000);
        poll.subscribe(subscriberNamed("later"));
        expect(calls).toEqual(["options message call-1{}", "options message call-2{}", "later message call-1{}"]);
        await poll.readNow();
        poll.stop();
        expect(calls.slice(3)).toEqual([
            "options message call-3{}",
            "later message call-3{}",
            "options cleared call-1{}",
            "later cleared call-1{}",
            "options cleared call-2{}",
        ]);
    });
    test("a subscriber that left is told nothing more, also for the rest of a hand-over in progress, and the poll keeps reading", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(), readOf(ring("call-1"), ring("call-2")), readOf());
        let unsubscribeLeaving = () => undefined;
        const poll = createAccountRealtimePoll({
            readAccountRealtime,
            onMessage: message => {
                calls.push(`options message ${message.messageKey}`);
                unsubscribeLeaving();
            },
            intervalMs: 1000,
        });
        await vi.advanceTimersByTimeAsync(0);
        unsubscribeLeaving = poll.subscribe(subscriberNamed("leaving"));
        await vi.advanceTimersByTimeAsync(2000);
        poll.stop();
        expect(calls).toEqual(["options message call-1", "options message call-2"]);
        expect(readAccountRealtime).toHaveBeenCalledTimes(3);
    });
    test("a subscriber that left during the clears of a read is told none of the rest", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1"), ring("call-2")), readOf());
        let unsubscribeLeaving = () => undefined;
        const poll = createAccountRealtimePoll({
            readAccountRealtime,
            onMessageCleared: message => {
                calls.push(`options cleared ${message.messageKey}`);
                unsubscribeLeaving();
            },
            isTransportBound: () => true,
        });
        await vi.advanceTimersByTimeAsync(0);
        unsubscribeLeaving = poll.subscribe({ onMessageCleared: subscriberNamed("leaving").onMessageCleared });
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["options cleared call-1", "options cleared call-2"]);
    });
    test("the hooks the poll was created with are called before a subscriber's, also for one that came before the first answer", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        poll.subscribe(subscriberNamed("subscriber"));
        await vi.advanceTimersByTimeAsync(0);
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", "subscriber message call-1{}", "options cleared call-1{}", "subscriber cleared call-1{}"]);
    });
    test("a subscriber without an onMessage hook counts as handed the messages and is told their clears", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(ring("call-2")), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        poll.subscribe({ onMessageCleared: subscriberNamed("clears only").onMessageCleared });
        await poll.readNow();
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["clears only cleared call-1{}", "clears only cleared call-2{}"]);
    });
    test("two gone messages of one type and key: each subscriber is told once, with the newest one it was handed", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf({ ...ring("call-1"), secondsLeft: 60 }), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(1000);
        expect(poll.acceptArrivedMessage({ ...ring("call-1", '{"n":2}'), secondsLeft: 5 })).toBe(true);
        await vi.advanceTimersByTimeAsync(9000);
        poll.subscribe(subscriberNamed("later"));
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual([
            "options message call-1{}",
            'options message call-1{"n":2}',
            "later message call-1{}",
            'options cleared call-1{"n":2}',
            "later cleared call-1{}",
        ]);
    });
    test("a subscriber that comes during a hand-over gets each message once", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1"), ring("call-2")));
        let subscribed = false;
        const poll = createAccountRealtimePoll({
            readAccountRealtime,
            onMessage: message => {
                calls.push(`options message ${message.messageKey}${message.payloadJson}`);
                if (subscribed)
                    return;
                subscribed = true;
                poll.subscribe(subscriberNamed("coming"));
            },
            isTransportBound: () => true,
        });
        await vi.advanceTimersByTimeAsync(0);
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", "coming message call-1{}", "coming message call-2{}", "options message call-2{}"]);
    });
    test("stop schedules no further read and clears nothing; the subscribers stay, so readNow and acceptArrivedMessage still reach them", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(ring("call-1"), ring("call-2")));
        const poll = createAccountRealtimePoll({ readAccountRealtime, intervalMs: 1000 });
        poll.subscribe(subscriberNamed("subscriber"));
        await vi.advanceTimersByTimeAsync(0);
        poll.stop();
        await vi.advanceTimersByTimeAsync(5000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(1);
        expect(calls).toEqual(["subscriber message call-1{}"]);
        await expect(poll.readNow()).resolves.toBe(1);
        expect(poll.acceptArrivedMessage(ring("call-3"))).toBe(true);
        expect(calls).toEqual(["subscriber message call-1{}", "subscriber message call-2{}", "subscriber message call-3{}"]);
    });
    test("a failed read hands nothing and clears nothing", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime, heldReads } = scriptedAccountRead(readOf(ring("call-1")), null, readOf(ring("call-1")));
        const onReadFailed = vi.fn();
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), onReadFailed, intervalMs: 1000 });
        await vi.advanceTimersByTimeAsync(1000);
        heldReads[0].fail(new Error("offline"));
        await vi.advanceTimersByTimeAsync(1000);
        poll.stop();
        expect(onReadFailed).toHaveBeenCalledTimes(1);
        expect(readAccountRealtime).toHaveBeenCalledTimes(3);
        expect(calls).toEqual(["options message call-1{}"]);
    });
    test("while a transport is bound the poll does not read, so a clear comes only with readNow or readAfterPing", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(), readOf(), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true, intervalMs: 1000 });
        await vi.advanceTimersByTimeAsync(10000);
        expect(readAccountRealtime).toHaveBeenCalledTimes(1);
        expect(calls).toEqual(["options message call-1{}"]);
        await poll.readNow();
        expect(calls).toEqual(["options message call-1{}", "options cleared call-1{}"]);
        expect(poll.acceptArrivedMessage(ring("call-2"))).toBe(true);
        const arrived = poll.readAfterPing();
        await vi.advanceTimersByTimeAsync(ACCOUNT_REALTIME_PING_REREAD_DELAY_MS);
        await expect(arrived).resolves.toBe(false);
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", "options cleared call-1{}", "options message call-2{}", "options cleared call-2{}"]);
    });
    test("two gone messages of one type and key are one clear, told by the newest", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, ...subscriberNamed("options"), isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        expect(poll.acceptArrivedMessage(ring("call-1", '{"n":2}'))).toBe(true);
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["options message call-1{}", 'options message call-1{"n":2}', 'options cleared call-1{"n":2}']);
    });
    test("an answer that comes back after the answer of a later-started read is left out: readNow resolves 0 and no credential is handed", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime, heldReads } = scriptedAccountRead(readOf(), null, null);
        const poll = createAccountRealtimePoll({
            readAccountRealtime,
            ...subscriberNamed("options"),
            onListenerCredential: credential => calls.push(`credential ${credential?.credential ?? null}`),
            isTransportBound: () => true,
        });
        await vi.advanceTimersByTimeAsync(0);
        const olderRead = poll.readNow();
        const newerRead = poll.readNow();
        heldReads[1].answer({ messages: [ring("call-2")], listenerCredential: credentialOf("newer") });
        await expect(newerRead).resolves.toBe(1);
        heldReads[0].answer({ messages: [ring("call-1")], listenerCredential: credentialOf("older") });
        await expect(olderRead).resolves.toBe(0);
        poll.stop();
        expect(calls).toEqual(["credential null", "options message call-2{}", "credential newer"]);
    });
    test("the known messages are updated before the hooks run: a hook that throws is not handed the same message by the next read", async () => {
        vi.useFakeTimers();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf(ring("call-1")));
        const onMessage = vi.fn(() => {
            throw new Error("hook failed");
        });
        const onReadFailed = vi.fn();
        const poll = createAccountRealtimePoll({ readAccountRealtime, onMessage, onReadFailed, isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        expect(onReadFailed).toHaveBeenCalledTimes(1);
        await expect(poll.readNow()).resolves.toBe(0);
        poll.stop();
        expect(onMessage).toHaveBeenCalledTimes(1);
    });
    test("a poll created without onMessage hands its messages to its subscribers", async () => {
        vi.useFakeTimers();
        const { calls, subscriberNamed } = callLog();
        const { readAccountRealtime } = scriptedAccountRead(readOf(ring("call-1")), readOf());
        const poll = createAccountRealtimePoll({ readAccountRealtime, isTransportBound: () => true });
        poll.subscribe(subscriberNamed("subscriber"));
        await vi.advanceTimersByTimeAsync(0);
        await poll.readNow();
        poll.stop();
        expect(calls).toEqual(["subscriber message call-1{}", "subscriber cleared call-1{}"]);
    });
});
describe("createAccountRealtimePoll: the client id of the read", () => {
    test("every read gets the poll's clientId option as its one argument, undefined without the option", async () => {
        vi.useFakeTimers();
        const withOption = vi.fn(async (_clientId) => readOf());
        const withoutOption = vi.fn(async (_clientId) => readOf());
        const pollWithOption = createAccountRealtimePoll({ readAccountRealtime: withOption, clientId: "client-7", isTransportBound: () => true });
        const pollWithoutOption = createAccountRealtimePoll({ readAccountRealtime: withoutOption, isTransportBound: () => true });
        await vi.advanceTimersByTimeAsync(0);
        await pollWithOption.readNow();
        const pinged = pollWithoutOption.readAfterPing();
        await vi.advanceTimersByTimeAsync(ACCOUNT_REALTIME_PING_REREAD_DELAY_MS);
        await expect(pinged).resolves.toBe(false);
        pollWithOption.stop();
        pollWithoutOption.stop();
        expect(withOption.mock.calls).toStrictEqual([["client-7"], ["client-7"]]);
        expect(withoutOption.mock.calls).toStrictEqual([[undefined], [undefined], [undefined]]);
    });
});
