import { afterEach, describe, expect, test, vi } from "vitest";
import { CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS, readRunStateAfterClientToolRequestPing, } from "./realtime-arrival";
/**
 * A ping of a transport that carries no message: the run-state read after it; an empty read (the ping overtook the
 * server's write) is followed by exactly one re-read after the delay, a read that delivered a request by none.
 */
const createCounter = () => ({
    accepted: 0,
    countAcceptedClientToolRequests() {
        return this.accepted;
    },
});
afterEach(() => {
    vi.useRealTimers();
});
describe("readRunStateAfterClientToolRequestPing", () => {
    test("an empty read after a ping triggers exactly one re-read after the delay", async () => {
        vi.useFakeTimers();
        const counter = createCounter();
        const readRunState = vi.fn(async (_conversationId) => {
            // the second read finds the request the server wrote meanwhile
            if (readRunState.mock.calls.length === 2)
                counter.accepted += 1;
        });
        const delivered = readRunStateAfterClientToolRequestPing(7, readRunState, counter);
        await vi.advanceTimersByTimeAsync(CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS - 1);
        expect(readRunState).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        await expect(delivered).resolves.toBe(true);
        expect(readRunState).toHaveBeenCalledTimes(2);
        expect(readRunState).toHaveBeenNthCalledWith(2, 7);
    });
    test("a second empty read is not followed by a third", async () => {
        vi.useFakeTimers();
        const readRunState = vi.fn(async (_conversationId) => undefined);
        const delivered = readRunStateAfterClientToolRequestPing(7, readRunState, createCounter(), 50);
        await vi.advanceTimersByTimeAsync(1000);
        await expect(delivered).resolves.toBe(false);
        expect(readRunState).toHaveBeenCalledTimes(2);
    });
    test("a read that delivered a request is not repeated", async () => {
        const counter = createCounter();
        const readRunState = vi.fn(async (_conversationId) => {
            counter.accepted += 1;
        });
        await expect(readRunStateAfterClientToolRequestPing(7, readRunState, counter)).resolves.toBe(true);
        expect(readRunState).toHaveBeenCalledTimes(1);
    });
});
