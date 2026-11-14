import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import type { ConversationChangeSignal, ConversationRunStatus } from "../../contract/conversation";
import {
  ConversationChangeSignalRules,
  type ConversationLiveUpdateReads,
  LIVE_READ_THROTTLE_MS,
} from "./conversation-change-signal-rules";

/**
 * The package's live-update rules, ported from the specs of the application's former signal hook
 * (`use-agent-conversation-signal.ts`): the first signal reads once and is the baseline, a counter advance reads
 * (throttled), a terminal status reads at once, re-reads the metadata and converges on `lastMessageId`, the summary
 * re-read, the status-check emissions of a binding, and the end of a subscription.
 */

class RecordedReads implements ConversationLiveUpdateReads {
  readonly changedMessageReads = vi.fn();
  readonly metadataReads = vi.fn();
  readonly loadedMessageIds = new Set<number>();
  runStatus: ConversationRunStatus | null = null;

  readChangedMessages = (): void => this.changedMessageReads();

  readConversationMetadata = (): void => this.metadataReads();

  hasLoadedMessage = (messageId: number): boolean => this.loadedMessageIds.has(messageId);

  showRunStatus = (runStatus: ConversationRunStatus | null): void => {
    this.runStatus = runStatus;
  };
}

const pushed = (signal: Partial<ConversationChangeSignal>): ConversationChangeSignal => ({
  changeCounter: 0,
  lastMessageId: null,
  runStatus: null,
  summaryUpdatedAt: null,
  ...signal,
});

const statusCheck = (runStatus: ConversationRunStatus, changeCounter = -1): ConversationChangeSignal => ({
  changeCounter,
  lastMessageId: null,
  runStatus,
  summaryUpdatedAt: null,
  isStatusCheck: true,
});

let reads: RecordedReads;
let rules: ConversationChangeSignalRules;

beforeEach(() => {
  vi.useFakeTimers();
  reads = new RecordedReads();
  rules = new ConversationChangeSignalRules(reads);
});

afterEach(() => {
  rules.dispose();
  vi.useRealTimers();
});

describe("summary re-read", () => {
  test("the first signal is the baseline, even when it already carries a summary", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1, summaryUpdatedAt: 100 }));
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2, summaryUpdatedAt: 100 }));
    expect(reads.metadataReads).not.toHaveBeenCalled();
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2, summaryUpdatedAt: 200 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(1);
  });

  test("a new conversation's first title re-reads, though the signals before it had no summaryUpdatedAt", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2 }));
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2, summaryUpdatedAt: 300 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(1);
  });
});

describe("terminal status", () => {
  test("re-reads the metadata on each terminal signal that advances the counter, not on a running baseline", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 2 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(1);
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 2 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(1);
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 3 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(2);
  });

  test("re-reads the metadata on a terminal baseline — the close landed before the subscription armed", () => {
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 5 }));
    expect(reads.metadataReads).toHaveBeenCalledTimes(1);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
  });

  test("reads at once, bypassing the throttle", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 2 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
  });

  test("keeps reading with backoff until the signal's lastMessageId is loaded", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 2, lastMessageId: 77 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
    vi.advanceTimersByTime(700);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(3);
    reads.loadedMessageIds.add(77);
    vi.advanceTimersByTime(5000);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(3);
  });

  test("gives up after six convergence reads", () => {
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 1, lastMessageId: 77 }));
    vi.advanceTimersByTime(60_000);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1 + 6);
  });
});

describe("counter advance", () => {
  test("reads on the first signal, then once per advance, throttled with one trailing read", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2 }));
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 3 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(LIVE_READ_THROTTLE_MS);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
  });

  test("a running signal without an advance reads nothing", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    vi.advanceTimersByTime(LIVE_READ_THROTTLE_MS);
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    vi.advanceTimersByTime(LIVE_READ_THROTTLE_MS);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    expect(reads.runStatus).toBe("running");
  });
});

describe("status-check emissions of the binding", () => {
  test("read while nothing was pushed, on a status change only, at once on a terminal status", () => {
    rules.receiveSignal(statusCheck("running", -1));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    expect(reads.runStatus).toBe("running");
    rules.receiveSignal(statusCheck("running", -2));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    rules.receiveSignal(statusCheck("idle", -3));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
    expect(reads.metadataReads).not.toHaveBeenCalled();
  });

  test("only correct the status once a pushed signal arrived", () => {
    rules.receiveSignal(pushed({ runStatus: "failed", changeCounter: 4 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    rules.receiveSignal(statusCheck("idle", 4));
    expect(reads.runStatus).toBe("idle");
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
  });
});

describe("status checks after the push path died", () => {
  test("read again on a status change once the binding reports the push path dead (a denied read), as before any push", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 4 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
    // The realtime read was denied and the re-subscribes failed: the binding's checks say the push path is dead.
    rules.receiveSignal({ ...statusCheck("running", 4), isPushPathAlive: false });
    // A running status reads throttled (one trailing read), as the old hook's single read path did.
    vi.advanceTimersByTime(LIVE_READ_THROTTLE_MS);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
    rules.receiveSignal({ ...statusCheck("running", 4), isPushPathAlive: false });
    vi.advanceTimersByTime(LIVE_READ_THROTTLE_MS);
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(2);
    rules.receiveSignal({ ...statusCheck("idle", 4), isPushPathAlive: false });
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(3);
    expect(reads.runStatus).toBe("idle");
  });

  test("a check that says the push path is alive only corrects the status", () => {
    rules.receiveSignal({ ...statusCheck("running", -1), isPushPathAlive: true });
    expect(reads.changedMessageReads).not.toHaveBeenCalled();
    expect(reads.runStatus).toBe("running");
  });
});

describe("end of the subscription", () => {
  test("clears the run status", () => {
    rules.receiveSignal(statusCheck("running"));
    rules.receiveEnd("gone");
    expect(reads.runStatus).toBeNull();
  });

  test("a disposed rule set reads nothing more", () => {
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 1 }));
    rules.receiveSignal(pushed({ runStatus: "running", changeCounter: 2 }));
    rules.dispose();
    vi.advanceTimersByTime(10_000);
    rules.receiveSignal(pushed({ runStatus: "idle", changeCounter: 3 }));
    expect(reads.changedMessageReads).toHaveBeenCalledTimes(1);
  });
});
