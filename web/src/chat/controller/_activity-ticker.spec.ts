import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import {
  activityTickerReducer,
  ActivityTickerAction,
  ActivityTickerQueue,
  ActivityTickerState,
  countWorkingHelpers,
  MAX_KEPT_TURNS,
  BACKLOG_LINE_PACE_MS,
  EMPTY_ACTIVITY_TICKER_QUEUE,
  INITIAL_ACTIVITY_TICKER_STATE,
  LINE_PACE_MS,
  MAX_WAITING_LINES,
  msUntilNextActivityLine,
  receiveActivityElements,
  releaseNextActivityLine,
  shownLinesInOrder,
} from "./activity-ticker";
import { ActivityElement } from "./merge-activity-elements";
import { SUCCESS_BEAT_MS, useActivityTicker } from "./use-activity-ticker";

const element = (
  toolCallMessageId: number,
  state: ActivityElement["state"],
  line: string | null,
  extra: Partial<ActivityElement> = {},
): ActivityElement => ({ toolCallMessageId, state, kind: "tool", line, ...extra });

const T0 = 1_000_000;

/** Receive the elements and release every line the pace allows at `now`. */
const receiveAndRelease = (queue: ActivityTickerQueue, elements: ActivityElement[], now: number) => {
  let nextQueue = receiveActivityElements(queue, elements, now);
  while (msUntilNextActivityLine(nextQueue, now) === 0) {
    nextQueue = releaseNextActivityLine(nextQueue, now);
  }
  return nextQueue;
};

describe("activity ticker queue", () => {
  it("shows the first line at once and holds the next one for the pace", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Looking for A")], T0);
    expect(queue.currentId).toBe(1);

    queue = receiveActivityElements(
      queue,
      [element(1, "running", "Looking for A"), element(2, "running", "Looking for B")],
      T0 + 100,
    );
    expect(queue.currentId).toBe(1);
    expect(msUntilNextActivityLine(queue, T0 + 100)).toBe(LINE_PACE_MS - 100);
  });

  it("replaces the current line in place when its step finishes", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Looking for A")], T0);
    queue = receiveAndRelease(queue, [element(1, "finished", "Found A")], T0 + LINE_PACE_MS);
    expect(queue.currentId).toBe(1);
    expect(queue.trailIds).toEqual([]);
    expect(queue.shownLines[1]).toMatchObject({ state: "finished", line: "Found A" });
  });

  it("moves the previous line up into the trail when the next step's line shows", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    queue = receiveAndRelease(queue, [element(2, "running", "Looking for B")], T0 + LINE_PACE_MS);
    expect(queue.currentId).toBe(2);
    expect(queue.trailIds).toEqual([1]);
  });

  it("keeps one waiting line per step: the finished line replaces the started one before it shows", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    queue = receiveActivityElements(queue, [element(2, "running", "Looking for B")], T0 + 10);
    queue = receiveActivityElements(queue, [element(2, "finished", "Found B")], T0 + 20);
    expect(queue.waitingLines).toHaveLength(1);
    expect(queue.waitingLines[0]).toMatchObject({ toolCallMessageId: 2, state: "finished", line: "Found B" });
  });

  it("paces faster while more than two lines wait", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    queue = receiveActivityElements(
      queue,
      [element(2, "running", "B"), element(3, "running", "C"), element(4, "running", "D")],
      T0 + 100,
    );
    expect(msUntilNextActivityLine(queue, T0 + 100)).toBe(BACKLOG_LINE_PACE_MS - 100);
  });

  it(`shows the oldest lines at once beyond ${MAX_WAITING_LINES} waiting, in order`, () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    queue = receiveActivityElements(
      queue,
      [2, 3, 4, 5, 6, 7].map((stepId) => element(stepId, "running", `Step ${stepId}`)),
      T0 + 100,
    );
    expect(queue.trailIds).toEqual([1, 2]);
    expect(queue.currentId).toBe(3);
    expect(queue.waitingLines.map((line) => line.toolCallMessageId)).toEqual([4, 5, 6, 7]);
  });

  it("updates a step still visible in the trail in place, without waiting", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Looking for A")], T0);
    queue = receiveAndRelease(queue, [element(2, "running", "Looking for B")], T0 + LINE_PACE_MS);
    queue = receiveActivityElements(queue, [element(1, "finished", "Found A")], T0 + LINE_PACE_MS + 10);
    expect(queue.waitingLines).toEqual([]);
    expect(queue.currentId).toBe(2);
    expect(queue.shownLines[1]).toMatchObject({ state: "finished", line: "Found A" });
  });

  it("updates any step already told in place, however far up it stands", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Looking for A")], T0);
    [2, 3, 4].forEach((stepId, index) => {
      queue = receiveAndRelease(queue, [element(stepId, "running", `Step ${stepId}`)], T0 + (index + 1) * LINE_PACE_MS);
    });
    queue = receiveActivityElements(queue, [element(1, "finished", "Found A")], T0 + 5 * LINE_PACE_MS);
    expect(queue.waitingLines).toEqual([]);
    expect(queue.currentId).toBe(4);
    expect(shownLinesInOrder(queue).map((line) => line.toolCallMessageId)).toEqual([1, 2, 3, 4]);
    expect(queue.shownLines[1]).toMatchObject({ state: "finished", line: "Found A" });
  });

  it("marks a step in the trail finished beside its told sentence when the finish came without one", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Looking for A")], T0);
    queue = receiveAndRelease(queue, [element(2, "running", "Looking for B")], T0 + LINE_PACE_MS);
    queue = receiveActivityElements(queue, [element(1, "finished", null)], T0 + LINE_PACE_MS + 10);
    expect(queue.waitingLines).toEqual([]);
    expect(queue.shownLines[1]).toMatchObject({ state: "finished", line: "Looking for A" });
  });

  it("marks the current step failed beside its told sentence when the failure came without one", () => {
    let queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "running", "Reading your website")], T0);
    queue = receiveAndRelease(queue, [element(1, "failed", null)], T0 + LINE_PACE_MS);
    expect(queue.currentId).toBe(1);
    expect(queue.shownLines[1]).toMatchObject({ state: "failed", line: "Reading your website" });
  });

  it("never shows a skipped step or a step without a sentence", () => {
    const queue = receiveActivityElements(
      EMPTY_ACTIVITY_TICKER_QUEUE,
      [element(1, "skipped", "Refused duplicate"), element(2, "running", null)],
      T0,
    );
    expect(queue.waitingLines).toEqual([]);
  });
});

describe("countWorkingHelpers", () => {
  it("counts a helper with several steps at work once", () => {
    expect(
      countWorkingHelpers([
        element(1, "running", "Three helpers are searching", { kind: "delegation" }),
        element(2, "running", "Searching north", { helperIndex: 1 }),
        element(3, "queued", "Searching north-east", { helperIndex: 1 }),
        element(4, "running", "Searching west", { helperIndex: 2 }),
        element(5, "finished", "Found Stade", { helperIndex: 3 }),
      ]),
    ).toBe(2);
  });

  it("tells apart the helpers of two delegate calls, whose numbers both start at 1", () => {
    expect(
      countWorkingHelpers([
        element(1, "running", "A helper works in the background", { kind: "background" }),
        element(2, "running", "Reading your reviews", { helperIndex: 1 }),
        element(3, "running", "Two helpers are searching", { kind: "delegation" }),
        element(4, "running", "Searching north", { helperIndex: 1 }),
        element(5, "skipped", "Refused duplicate", { helperIndex: 2 }),
      ]),
    ).toBe(2);
  });
});

describe("activityTickerReducer", () => {
  const TURN_MESSAGE_ID = 77;

  const liveState = (queue: ActivityTickerQueue): ActivityTickerState => ({
    ...INITIAL_ACTIVITY_TICKER_STATE,
    queue,
    phase: "live",
    turnMessageId: TURN_MESSAGE_ID,
    wasRunning: true,
  });

  const received = (
    elements: ActivityElement[],
    running: boolean,
    now: number,
    extra: { failed?: boolean; turnMessageId?: number | null; keepHistory?: boolean } = {},
  ): ActivityTickerAction => ({
    type: "elementsReceived",
    elements,
    running,
    failed: extra.failed ?? false,
    turnMessageId: extra.turnMessageId === undefined ? TURN_MESSAGE_ID : extra.turnMessageId,
    keepHistory: extra.keepHistory ?? false,
    now,
  });

  /** A turn that told "Found A" and "Found B", ended at T0 + 35 s and now stands in the face's success beat. */
  const doneState = (): ActivityTickerState => {
    const queue = receiveAndRelease(
      EMPTY_ACTIVITY_TICKER_QUEUE,
      [element(1, "finished", "Found A"), element(2, "finished", "Found B")],
      T0,
    );
    return activityTickerReducer(
      liveState(queue),
      received([element(1, "finished", "Found A"), element(2, "finished", "Found B")], false, T0 + 35_000),
    );
  };

  it("goes live and starts clean when a turn starts", () => {
    const previousTurn = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    const state = activityTickerReducer(
      { ...INITIAL_ACTIVITY_TICKER_STATE, queue: previousTurn },
      received([], true, T0 + 5000, { turnMessageId: null }),
    );
    expect(state.phase).toBe("live");
    expect(state.queue.currentId).toBeNull();
    expect(state.turnMessageId).toBeNull();
  });

  it("shows the waiting lines at once and holds the success beat when the turn ends", () => {
    const queue = receiveActivityElements(
      receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0),
      [element(2, "finished", "Found B")],
      T0 + 100,
    );
    const state = activityTickerReducer(
      liveState(queue),
      received([element(1, "finished", "Found A"), element(2, "finished", "Found B")], false, T0 + 35_000),
    );
    expect(state.phase).toBe("done");
    expect(state.queue.currentId).toBe(2);
    expect(state.queue.trailIds).toEqual([1]);
  });

  it("skips the success beat when the turn failed or told no line", () => {
    const toldQueue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    const failedTurn = activityTickerReducer(
      liveState(toldQueue),
      received([element(1, "finished", "Found A")], false, T0 + 1000, { failed: true }),
    );
    expect(failedTurn.phase).toBe("idle");

    const silentTurn = activityTickerReducer(liveState(EMPTY_ACTIVITY_TICKER_QUEUE), received([], false, T0 + 1000));
    expect(silentTurn.phase).toBe("idle");
  });

  it("starts clean when a shown step is gone from the elements (another turn)", () => {
    const queue = receiveAndRelease(EMPTY_ACTIVITY_TICKER_QUEUE, [element(1, "finished", "Found A")], T0);
    const state = activityTickerReducer(
      liveState(queue),
      received([element(9, "running", "Looking for Z")], true, T0 + 2000, { turnMessageId: 78 }),
    );
    expect(state.queue.shownLines[1]).toBeUndefined();
    expect(state.queue.waitingLines.map((line) => line.toolCallMessageId)).toEqual([9]);
    expect(state.turnMessageId).toBe(78);
  });

  it("keeps the finished turn under its message after the Done beat when the history is kept", () => {
    const state = activityTickerReducer(doneState(), { type: "doneBeatOver", keepHistory: true });
    expect(state.phase).toBe("idle");
    expect(state.keptTurns).toEqual([
      {
        turnMessageId: TURN_MESSAGE_ID,
        lines: [
          expect.objectContaining({ toolCallMessageId: 1, line: "Found A" }),
          expect.objectContaining({ toolCallMessageId: 2, line: "Found B" }),
        ],
      },
    ]);
  });

  it("lets the finished turn go after the Done beat when the history is not kept", () => {
    const state = activityTickerReducer(doneState(), { type: "doneBeatOver", keepHistory: false });
    expect(state.phase).toBe("idle");
    expect(state.keptTurns).toEqual([]);
  });

  it("keeps a turn still in its Done beat when the next turn starts", () => {
    const state = activityTickerReducer(
      doneState(),
      received([], true, T0 + 36_000, { turnMessageId: null, keepHistory: true }),
    );
    expect(state.phase).toBe("live");
    expect(state.keptTurns.map((keptTurn) => keptTurn.turnMessageId)).toEqual([TURN_MESSAGE_ID]);
    expect(state.queue.currentId).toBeNull();
  });

  it("keeps one entry per turn message and at most the newest turns", () => {
    const keptTwice = activityTickerReducer(
      {
        ...doneState(),
        keptTurns: [{ turnMessageId: TURN_MESSAGE_ID, lines: [] }],
      },
      { type: "doneBeatOver", keepHistory: true },
    );
    expect(keptTwice.keptTurns).toHaveLength(1);
    expect(keptTwice.keptTurns[0].lines).toHaveLength(2);

    const olderTurns = Array.from({ length: MAX_KEPT_TURNS }, (_, index) => ({
      turnMessageId: index + 1,
      lines: [],
    }));
    const capped = activityTickerReducer(
      { ...doneState(), keptTurns: olderTurns },
      { type: "doneBeatOver", keepHistory: true },
    );
    expect(capped.keptTurns).toHaveLength(MAX_KEPT_TURNS);
    expect(capped.keptTurns[0].turnMessageId).toBe(2);
    expect(capped.keptTurns[MAX_KEPT_TURNS - 1].turnMessageId).toBe(TURN_MESSAGE_ID);
  });

  it("keeps nothing of a turn the endpoint never named", () => {
    const state = activityTickerReducer(
      { ...doneState(), turnMessageId: null },
      { type: "doneBeatOver", keepHistory: true },
    );
    expect(state.keptTurns).toEqual([]);
  });
});

describe("useActivityTicker", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(T0);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  type HookParams = {
    elements: ActivityElement[];
    running: boolean;
    turnMessageId?: number | null;
    keepActivityHistoryAfterAnswer?: boolean;
  };

  // Typed through HookParams: inferred from the `elements: []` literal alone, the props would be `never[]`.
  const renderTicker = (initialProps: HookParams) =>
    renderHook((params: HookParams) => useActivityTicker(params), { initialProps });

  const newestLineId = (ticker: ReturnType<typeof useActivityTicker>) =>
    ticker.currentTurn?.lines[ticker.currentTurn.lines.length - 1]?.toolCallMessageId;

  it("puts a burst beside the face one line at a time, then holds the lines for the success beat", () => {
    const { result, rerender } = renderTicker({ elements: [], running: false });
    rerender({ elements: [], running: true });
    expect(result.current.phase).toBe("live");
    expect(result.current.currentTurn).toBeNull();

    rerender({
      elements: [element(1, "finished", "Found A"), element(2, "running", "Looking for B")],
      running: true,
      turnMessageId: 77,
    });
    act(() => {
      vi.advanceTimersByTime(0);
    });
    expect(newestLineId(result.current)).toBe(1);
    expect(result.current.currentTurn?.turnMessageId).toBe(77);

    act(() => {
      vi.advanceTimersByTime(LINE_PACE_MS - 1);
    });
    expect(newestLineId(result.current)).toBe(1);
    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(result.current.currentTurn?.lines.map((line) => line.toolCallMessageId)).toEqual([1, 2]);

    rerender({
      elements: [element(1, "finished", "Found A"), element(2, "finished", "Found B")],
      running: false,
      turnMessageId: 77,
    });
    expect(result.current.phase).toBe("done");
    expect(result.current.currentTurn?.phase).toBe("done");
    expect(result.current.currentTurn?.lines.map((line) => line.line)).toEqual(["Found A", "Found B"]);

    act(() => {
      vi.advanceTimersByTime(SUCCESS_BEAT_MS);
    });
    expect(result.current.phase).toBe("idle");
    expect(result.current.currentTurn).toBeNull();
    expect(result.current.historyTurns).toEqual([]);
  });

  it("stands the finished turn in the thread from its success beat on while the history is kept", () => {
    const finishedElements = [element(1, "finished", "Found A")];
    const { result, rerender } = renderTicker({ elements: [], running: false, keepActivityHistoryAfterAnswer: true });
    rerender({ elements: [], running: true, keepActivityHistoryAfterAnswer: true });
    rerender({ elements: finishedElements, running: true, turnMessageId: 77, keepActivityHistoryAfterAnswer: true });
    expect(result.current.historyTurns).toEqual([]);

    rerender({ elements: finishedElements, running: false, turnMessageId: 77, keepActivityHistoryAfterAnswer: true });
    const finishedTurn = expect.objectContaining({
      turnMessageId: 77,
      phase: "done",
      lines: [expect.objectContaining({ line: "Found A" })],
    });
    // The success beat: the lines leave the face and already stand in the thread.
    expect(result.current.currentTurn?.phase).toBe("done");
    expect(result.current.historyTurns).toEqual([finishedTurn]);

    act(() => {
      vi.advanceTimersByTime(SUCCESS_BEAT_MS);
    });
    expect(result.current.currentTurn).toBeNull();
    expect(result.current.historyTurns).toEqual([finishedTurn]);

    // Switching the history off takes the kept lines away at once.
    rerender({ elements: finishedElements, running: false, turnMessageId: 77, keepActivityHistoryAfterAnswer: false });
    expect(result.current.historyTurns).toEqual([]);
  });

  it("never stands a turn the endpoint did not name in the thread", () => {
    const finishedElements = [element(1, "finished", "Found A")];
    const { result, rerender } = renderTicker({ elements: [], running: false, keepActivityHistoryAfterAnswer: true });
    rerender({ elements: [], running: true, keepActivityHistoryAfterAnswer: true });
    rerender({ elements: finishedElements, running: true, turnMessageId: null, keepActivityHistoryAfterAnswer: true });
    rerender({ elements: finishedElements, running: false, turnMessageId: null, keepActivityHistoryAfterAnswer: true });
    expect(result.current.currentTurn?.phase).toBe("done");
    expect(result.current.historyTurns).toEqual([]);
  });
});
