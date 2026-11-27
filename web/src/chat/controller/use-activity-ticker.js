import { useEffect, useMemo, useReducer } from "react";
import { activityTickerReducer, countFinishedSteps, countWorkingHelpers, BACKLOG_LINE_PACE_MS, INITIAL_ACTIVITY_TICKER_STATE, LINE_PACE_MS, msUntilNextActivityLine, shownLinesInOrder, } from "./activity-ticker";
/**
 * How long the face's success beat rests before the face turns back to idle — counted from the start of the success
 * roll. The ticker keeps or drops the turn's lines once it is over; an app's face times its success roll by it.
 */
export const SUCCESS_BEAT_MS = 1400;
const toKeptTurn = (keptTurn) => ({
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
export const useActivityTicker = (params) => {
    const { elements, running, turnMessageId = null, failed = false, keepActivityHistoryAfterAnswer = false, linePaceMs = LINE_PACE_MS, backlogLinePaceMs = BACKLOG_LINE_PACE_MS, successBeatMs = SUCCESS_BEAT_MS, } = params;
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
        if (state.phase !== "live")
            return undefined;
        const waitMs = msUntilNextActivityLine(state.queue, Date.now(), linePaceMs, backlogLinePaceMs);
        if (waitMs === null)
            return undefined;
        const timer = setTimeout(() => dispatch({ type: "nextLineDue", now: Date.now() }), waitMs);
        return () => clearTimeout(timer);
    }, [state.phase, state.queue, linePaceMs, backlogLinePaceMs]);
    useEffect(() => {
        if (state.phase !== "done")
            return undefined;
        const timer = setTimeout(() => dispatch({ type: "doneBeatOver", keepHistory: keepActivityHistoryAfterAnswer }), successBeatMs);
        return () => clearTimeout(timer);
    }, [state.phase, keepActivityHistoryAfterAnswer, successBeatMs]);
    return useMemo(() => {
        const { queue, phase } = state;
        const lines = shownLinesInOrder(queue);
        const currentTurn = phase !== "idle" && lines.length > 0
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
        const finishedTurnOnScreen = currentTurn?.phase === "done" && currentTurn.turnMessageId !== null ? currentTurn : null;
        const historyTurns = !keepActivityHistoryAfterAnswer
            ? []
            : finishedTurnOnScreen
                ? [...keptTurns, finishedTurnOnScreen]
                : keptTurns;
        return { phase, currentTurn, historyTurns };
    }, [state, elements, keepActivityHistoryAfterAnswer]);
};
