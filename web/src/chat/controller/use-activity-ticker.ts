import { useEffect, useMemo, useReducer } from "react";

import {
  activityTickerReducer,
  ActivityTickerLine,
  countFinishedSteps,
  countWorkingHelpers,
  ActivityTickerPhase,
  INITIAL_ACTIVITY_TICKER_STATE,
  KeptActivityTurn,
  msUntilNextActivityLine,
  shownLinesInOrder,
} from "./activity-ticker";
import { ActivityElement } from "./merge-activity-elements";

/**
 * How long the face's success beat rests before the face turns back to idle — counted from the start of the success
 * roll. The ticker keeps or drops the turn's lines once it is over; an app's face times its success roll by it.
 */
export const SUCCESS_BEAT_MS = 1400;

interface UseActivityTickerParams {
  /** The rows of the running turn from {@link useConversationActivity}, in row order. */
  elements: ActivityElement[];
  /** Whether the endpoint says the turn is still in flight. */
  running: boolean;
  /** The message that started the turn, as the endpoint named it; null until it did. */
  turnMessageId?: number | null;
  /** The turn ended in an error: no success beat, nothing kept. */
  failed?: boolean;
  /**
   * After the answer, each finished turn's lines stay in the thread above its answer, under the message that started
   * it; false = they fade out with the line beside the face and nothing remains.
   */
  keepActivityHistoryAfterAnswer?: boolean;
}

/** One turn's lines — the turn running now, in the face's success beat, or kept in the thread. */
export interface ActivityTickerTurn {
  /** The message that started the turn: its kept history stands right after it. Null until the endpoint named it. */
  turnMessageId: number | null;
  /** live = the turn runs; done = it ended. */
  phase: "live" | "done";
  /** Every line told in the turn, oldest first; while live, the last one stands beside the face. */
  lines: ActivityTickerLine[];
  /** Steps finished or failed so far. */
  stepCount: number;
  /** Steps delegation helpers are running right now. */
  helperCount: number;
  /** When the running turn started on this screen (ms since epoch), for the elapsed clock; null once it ended. */
  liveSince: number | null;
}

/** What the ticker shows right now — read by AgentActivity (the face and its lines) and the history in the thread. */
export interface ActivityTicker {
  /** The phase of the turn on screen; the face keeps working while it is live. */
  phase: ActivityTickerPhase;
  /** The turn running now or in the face's success beat; null when idle and before the turn's first line. */
  currentTurn: ActivityTickerTurn | null;
  /**
   * The finished turns whose lines stand in the thread, under the message that started each, oldest first: every kept
   * turn plus the turn in the face's success beat. Empty unless the history is kept; a turn the endpoint never named
   * is never among them (it has no message to stand under).
   */
  historyTurns: ActivityTickerTurn[];
}

const toKeptTurn = (keptTurn: KeptActivityTurn): ActivityTickerTurn => ({
  turnMessageId: keptTurn.turnMessageId,
  phase: "done",
  lines: keptTurn.lines,
  stepCount: keptTurn.lines.length,
  helperCount: 0,
  liveSince: null,
});

/**
 * Paces the activity lines of a running turn at the prototype's ThinkingIndicator rhythm. The
 * endpoint delivers lines in bursts; the ticker puts them beside the face one at a time — each stays at least 2.2 s,
 * or 1.4 s while more than two wait — and never falls more than a few lines behind. When the turn ends, the rest is
 * shown at once and the face plays its success beat ({@link SUCCESS_BEAT_MS}); the turn's lines then stay in the
 * thread or are gone, as `keepActivityHistoryAfterAnswer` says. The state machine lives in
 * {@link activityTickerReducer}; this hook only owns the timers.
 */
export const useActivityTicker = (params: UseActivityTickerParams): ActivityTicker => {
  const { elements, running, turnMessageId = null, failed = false, keepActivityHistoryAfterAnswer = false } = params;
  const [state, dispatch] = useReducer(activityTickerReducer, INITIAL_ACTIVITY_TICKER_STATE);

  useEffect(() => {
    dispatch({
      type: "elementsReceived",
      elements,
      running,
      failed,
      turnMessageId,
      keepHistory: keepActivityHistoryAfterAnswer,
      now: Date.now(),
    });
    // keepActivityHistoryAfterAnswer is read when a turn leaves the screen, it never triggers a receive of its own.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [elements, running, failed, turnMessageId]);

  // One timer for the next waiting line; every queue change re-plans it.
  useEffect(() => {
    if (state.phase !== "live") return undefined;
    const waitMs = msUntilNextActivityLine(state.queue, Date.now());
    if (waitMs === null) return undefined;
    const timer = setTimeout(() => dispatch({ type: "nextLineDue", now: Date.now() }), waitMs);
    return () => clearTimeout(timer);
  }, [state.phase, state.queue]);

  useEffect(() => {
    if (state.phase !== "done") return undefined;
    const timer = setTimeout(
      () => dispatch({ type: "doneBeatOver", keepHistory: keepActivityHistoryAfterAnswer }),
      SUCCESS_BEAT_MS,
    );
    return () => clearTimeout(timer);
  }, [state.phase, keepActivityHistoryAfterAnswer]);

  return useMemo(() => {
    const { queue, phase } = state;
    const lines = shownLinesInOrder(queue);
    const currentTurn: ActivityTickerTurn | null =
      phase !== "idle" && lines.length > 0
        ? {
            turnMessageId: state.turnMessageId,
            phase,
            lines,
            stepCount: countFinishedSteps(elements),
            helperCount: phase === "live" ? countWorkingHelpers(elements) : 0,
            liveSince: phase === "live" ? state.liveSince : null,
          }
        : null;
    // Switching the history off takes the kept lines away at once; a turn on screen is never shown twice. The turn in
    // its success beat already stands in the thread, so its lines grow in there as they leave the face.
    const keptTurns = state.keptTurns
      .filter((keptTurn) => currentTurn === null || keptTurn.turnMessageId !== currentTurn.turnMessageId)
      .map(toKeptTurn);
    const finishedTurnOnScreen =
      currentTurn?.phase === "done" && currentTurn.turnMessageId !== null ? currentTurn : null;
    const historyTurns = !keepActivityHistoryAfterAnswer
      ? []
      : finishedTurnOnScreen
        ? [...keptTurns, finishedTurnOnScreen]
        : keptTurns;
    return { phase, currentTurn, historyTurns };
  }, [state, elements, keepActivityHistoryAfterAnswer]);
};
