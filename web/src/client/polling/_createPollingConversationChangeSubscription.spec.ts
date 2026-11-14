import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import {
  type AgentsConversation,
  AgentsClientError,
  type ConversationChangeSignal,
  type ConversationRunStatus,
  type ConversationSubscriptionEndReason,
} from "../../contract/conversation";
import {
  ConversationChangeSignalRules,
  type ConversationLiveUpdateReads,
} from "../../chat/controller/conversation-change-signal-rules";
import {
  createPollingConversationChangeSubscription,
  type PollingConversationChangeSubscriptionOptions,
  type PollingDocumentVisibility,
  type PollingTimers,
} from "./createPollingConversationChangeSubscription";

/**
 * The polling default of the live updates: intervals by run status, the read after a local send, the
 * pause while the page is hidden, backoff, the end on `notFound` / `forbidden`, no overlapping reads, no timer after
 * the unsubscribe — and the signals it numbers driving the package rules without a special case.
 */

const buildConversation = (fields: Partial<AgentsConversation> = {}): AgentsConversation => ({
  id: 1,
  agentSpaceKey: 7,
  runState: "IDLE",
  agentTurnOwedAt: null,
  title: "Visits",
  titleManuallyEdited: false,
  summary: null,
  created: null,
  updated: null,
  purposeName: null,
  initiatorType: null,
  scheduleId: null,
  entryPointKey: null,
  progressSteps: [],
  lastMessageId: 10,
  ...fields,
});

/** The metadata read: answers the current conversation, a queued failure, or holds the answer until released. */
class FakeMetadataReader {
  conversation = buildConversation();
  readCount = 0;
  holdsAnswers = false;
  readonly queuedFailures: unknown[] = [];
  protected readonly heldAnswers: Array<() => void> = [];

  read = (conversationId: number): Promise<AgentsConversation> => {
    this.readCount += 1;
    const failure = this.queuedFailures.shift();
    if (failure !== undefined) return Promise.reject(failure);
    const answer = { ...this.conversation, id: conversationId };
    if (!this.holdsAnswers) return Promise.resolve(answer);
    return new Promise((resolveRead) => this.heldAnswers.push(() => resolveRead(answer)));
  };

  releaseHeldAnswers = (): void => {
    for (const releaseAnswer of this.heldAnswers.splice(0)) releaseAnswer();
  };
}

class FakeDocumentVisibility implements PollingDocumentVisibility {
  hidden = false;
  readonly listeners = new Set<() => void>();

  isHidden = (): boolean => this.hidden;

  listen = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  setHidden = (hidden: boolean): void => {
    this.hidden = hidden;
    for (const listener of [...this.listeners]) listener();
  };
}

/** The injected timers — vitest's fake clock behind them. */
const fakeClockTimers: PollingTimers = {
  setTimeout: (callback, delayMs) => setTimeout(callback, delayMs),
  clearTimeout: (timerHandle) => clearTimeout(timerHandle as ReturnType<typeof setTimeout>),
  now: () => Date.now(),
};

let reader: FakeMetadataReader;
let visibility: FakeDocumentVisibility;
let signals: ConversationChangeSignal[];
let endReasons: ConversationSubscriptionEndReason[];

const subscribe = (options: PollingConversationChangeSubscriptionOptions = {}) => {
  const polling = createPollingConversationChangeSubscription(reader.read, {
    timers: fakeClockTimers,
    documentVisibility: visibility,
    ...options,
  });
  const unsubscribe = polling.subscribeToConversationChanges(
    1,
    (signal) => signals.push(signal),
    (reason) => endReasons.push(reason),
  );
  return { polling, unsubscribe };
};

/** Lets the clock run `ms` and every read answer that became due. */
const elapse = (ms: number) => vi.advanceTimersByTimeAsync(ms);

beforeEach(() => {
  vi.useFakeTimers();
  reader = new FakeMetadataReader();
  visibility = new FakeDocumentVisibility();
  signals = [];
  endReasons = [];
});

afterEach(() => {
  vi.useRealTimers();
});

describe("reading and numbering", () => {
  test("reads at once and emits the first signal as the baseline: counter 0, the newest message, the status", async () => {
    subscribe();
    await elapse(0);
    expect(reader.readCount).toBe(1);
    expect(signals).toEqual([{ changeCounter: 0, lastMessageId: 10, runStatus: "idle", summaryUpdatedAt: 0 }]);
  });

  test("reads every 20 s while idle and emits nothing while nothing changed", async () => {
    subscribe();
    await elapse(0);
    await elapse(19_999);
    expect(reader.readCount).toBe(1);
    await elapse(1);
    expect(reader.readCount).toBe(2);
    await elapse(40_000);
    expect(reader.readCount).toBe(4);
    expect(signals).toHaveLength(1);
  });

  test("advances the counter on a status change and on a new message under the same status, every 2 s while running", async () => {
    subscribe();
    await elapse(0);
    reader.conversation = buildConversation({ runState: "RUNNING", lastMessageId: 11 });
    await elapse(20_000);
    expect(signals[1]).toEqual({ changeCounter: 1, lastMessageId: 11, runStatus: "running", summaryUpdatedAt: 0 });

    reader.conversation = buildConversation({ runState: "RUNNING", lastMessageId: 12 });
    await elapse(2_000);
    expect(reader.readCount).toBe(3);
    expect(signals[2]).toMatchObject({ changeCounter: 2, lastMessageId: 12, runStatus: "running" });

    await elapse(2_000);
    expect(reader.readCount).toBe(4);
    expect(signals).toHaveLength(3);

    reader.conversation = buildConversation({ runState: "IDLE", lastMessageId: 13 });
    await elapse(2_000);
    expect(signals[3]).toMatchObject({ changeCounter: 3, lastMessageId: 13, runStatus: "idle" });
    await elapse(2_000);
    expect(reader.readCount).toBe(5);
  });

  test("an idle conversation the server owes a turn counts as running", async () => {
    reader.conversation = buildConversation({ agentTurnOwedAt: "2026-11-10 10:00:00" });
    subscribe();
    await elapse(0);
    expect(signals[0]?.runStatus).toBe("running");
    await elapse(2_000);
    expect(reader.readCount).toBe(2);
  });

  test("a new title or summary advances summaryUpdatedAt only", async () => {
    subscribe();
    await elapse(0);
    reader.conversation = buildConversation({ title: "Visits last week" });
    await elapse(20_000);
    expect(signals[1]).toEqual({ changeCounter: 0, lastMessageId: 10, runStatus: "idle", summaryUpdatedAt: 1 });
  });

  test("without lastMessageId in the metadata, every poll of a running turn is a change", async () => {
    reader.conversation = buildConversation({ runState: "RUNNING", lastMessageId: undefined });
    subscribe();
    await elapse(0);
    await elapse(2_000);
    await elapse(2_000);
    expect(signals.map((signal) => signal.changeCounter)).toEqual([0, 1, 2]);
    expect(signals.every((signal) => signal.lastMessageId === null)).toBe(true);
  });

  test("stops polling a completed conversation", async () => {
    reader.conversation = buildConversation({ runState: "COMPLETED" });
    subscribe();
    await elapse(0);
    await elapse(120_000);
    expect(reader.readCount).toBe(1);
  });
});

describe("local activity", () => {
  test("a send reads at once and keeps the 2 s interval for 10 s although the conversation still reads idle", async () => {
    const { polling } = subscribe();
    await elapse(0);
    await elapse(5_000);
    polling.notifyLocalActivity(1);
    await elapse(0);
    expect(reader.readCount).toBe(2);
    await elapse(2_000);
    expect(reader.readCount).toBe(3);
    await elapse(8_000);
    expect(reader.readCount).toBe(7);
    // The window is over: back to the idle interval.
    await elapse(19_999);
    expect(reader.readCount).toBe(7);
    await elapse(1);
    expect(reader.readCount).toBe(8);
  });

  test("reaches only the subscriptions of that conversation", async () => {
    const { polling } = subscribe();
    await elapse(0);
    polling.notifyLocalActivity(2);
    await elapse(0);
    expect(reader.readCount).toBe(1);
  });
});

describe("page visibility", () => {
  test("no read while the page is hidden, one read when it shows again", async () => {
    subscribe();
    await elapse(0);
    visibility.setHidden(true);
    await elapse(120_000);
    expect(reader.readCount).toBe(1);
    visibility.setHidden(false);
    await elapse(0);
    expect(reader.readCount).toBe(2);
    await elapse(20_000);
    expect(reader.readCount).toBe(3);
  });

  test("a read that lands while the page is hidden arms no timer", async () => {
    reader.holdsAnswers = true;
    subscribe();
    await elapse(0);
    visibility.setHidden(true);
    reader.releaseHeldAnswers();
    await elapse(120_000);
    expect(reader.readCount).toBe(1);
  });
});

describe("failed reads", () => {
  test("back off exponentially up to the cap and return to the interval after a good read", async () => {
    reader.conversation = buildConversation({ runState: "RUNNING" });
    subscribe();
    await elapse(0);
    reader.queuedFailures.push(new AgentsClientError("network"), new AgentsClientError("network"), new Error("offline"));
    reader.queuedFailures.push(new AgentsClientError("network"), new AgentsClientError("network"));
    await elapse(2_000); // fails → next in 4 s
    expect(reader.readCount).toBe(2);
    await elapse(4_000); // fails → 8 s
    expect(reader.readCount).toBe(3);
    await elapse(8_000); // fails → 16 s
    expect(reader.readCount).toBe(4);
    await elapse(16_000); // fails → 32 s
    expect(reader.readCount).toBe(5);
    await elapse(32_000); // fails → 64 s, capped at 60 s
    expect(reader.readCount).toBe(6);
    await elapse(59_999);
    expect(reader.readCount).toBe(6);
    await elapse(1); // succeeds → back to 2 s
    expect(reader.readCount).toBe(7);
    await elapse(2_000);
    expect(reader.readCount).toBe(8);
    expect(endReasons).toEqual([]);
  });

  test.each([
    ["notFound", "gone"],
    ["forbidden", "forbidden"],
  ] as const)("a %s read ends the subscription with %s and stops reading", async (errorKind, endReason) => {
    subscribe();
    await elapse(0);
    reader.queuedFailures.push(new AgentsClientError(errorKind));
    await elapse(20_000);
    expect(endReasons).toEqual([endReason]);
    await elapse(120_000);
    expect(reader.readCount).toBe(2);
  });
});

describe("no overlap, no leftovers", () => {
  test("a send during a read reads once more after it, never in parallel", async () => {
    reader.holdsAnswers = true;
    const { polling } = subscribe();
    await elapse(0);
    polling.notifyLocalActivity(1);
    polling.notifyLocalActivity(1);
    await elapse(0);
    expect(reader.readCount).toBe(1);
    reader.releaseHeldAnswers();
    await elapse(0);
    expect(reader.readCount).toBe(2);
    reader.releaseHeldAnswers();
    await elapse(0);
    expect(reader.readCount).toBe(2);
  });

  test("the unsubscribe stops every timer, drops a read in flight and stops listening to the page", async () => {
    reader.holdsAnswers = true;
    const { unsubscribe } = subscribe();
    await elapse(0);
    unsubscribe();
    reader.releaseHeldAnswers();
    await elapse(120_000);
    expect(reader.readCount).toBe(1);
    expect(signals).toEqual([]);
    expect(visibility.listeners.size).toBe(0);
    expect(vi.getTimerCount()).toBe(0);
  });
});

describe("the package rules over polled signals", () => {
  class RecordedReads implements ConversationLiveUpdateReads {
    changedMessageReads = 0;
    metadataReads = 0;
    readonly loadedMessageIds = new Set<number>();
    runStatus: ConversationRunStatus | null = null;

    readChangedMessages = (): void => {
      this.changedMessageReads += 1;
    };

    readConversationMetadata = (): void => {
      this.metadataReads += 1;
    };

    hasLoadedMessage = (messageId: number): boolean => this.loadedMessageIds.has(messageId);

    showRunStatus = (runStatus: ConversationRunStatus | null): void => {
      this.runStatus = runStatus;
    };
  }

  test("a whole turn: the baseline syncs, the status and each new message read, the end reads at once and converges", async () => {
    const reads = new RecordedReads();
    const rules = new ConversationChangeSignalRules(reads);
    const polling = createPollingConversationChangeSubscription(reader.read, {
      timers: fakeClockTimers,
      documentVisibility: visibility,
    });
    polling.subscribeToConversationChanges(1, rules.receiveSignal, rules.receiveEnd);
    await elapse(0);
    // The baseline is idle: one read and one metadata read; idle polls after it read nothing.
    expect(reads.changedMessageReads).toBe(1);
    expect(reads.metadataReads).toBe(1);
    reads.loadedMessageIds.add(10);
    await elapse(5_000);

    // The owner sends: the server stored the message and marks the turn.
    reader.conversation = buildConversation({ runState: "RUNNING", lastMessageId: 11 });
    polling.notifyLocalActivity(1);
    await elapse(0);
    expect(reads.runStatus).toBe("running");
    expect(reads.changedMessageReads).toBe(2);

    // A tool message under the same status.
    reader.conversation = buildConversation({ runState: "RUNNING", lastMessageId: 12 });
    await elapse(2_000);
    expect(reads.changedMessageReads).toBe(3);

    // The answer and the end of the turn: read at once, metadata again, converge on message 13.
    reader.conversation = buildConversation({ runState: "IDLE", lastMessageId: 13, title: "Visits last week" });
    await elapse(2_000);
    expect(reads.runStatus).toBe("idle");
    expect(reads.changedMessageReads).toBe(4);
    expect(reads.metadataReads).toBeGreaterThanOrEqual(2);
    await elapse(700);
    expect(reads.changedMessageReads).toBe(5);
    reads.loadedMessageIds.add(13);
    await elapse(30_000);
    expect(reads.changedMessageReads).toBe(5);
    rules.dispose();
  });
});
