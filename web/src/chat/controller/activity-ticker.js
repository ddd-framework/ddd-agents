import { visibleActivityElements } from "./merge-activity-elements";
/**
 * The least time a line stands beside the face before the next one takes its place — the concept's rhythm (the agent
 * prototype's ThinkingIndicator swaps its line every 2.2 s), so every sentence can be read between two swaps.
 */
export const LINE_PACE_MS = 2200;
/** The shorter wait while more than {@link BACKLOG_THRESHOLD} lines are queued, so a burst catches up. */
export const BACKLOG_LINE_PACE_MS = 1400;
const BACKLOG_THRESHOLD = 2;
/**
 * More queued lines than this are shown at once, oldest first: one narrator answer carries up to 8 lines, and the
 * ticker must never trail the turn by more than a few seconds.
 */
export const MAX_WAITING_LINES = 4;
/** Finished turns kept in the thread at most (keepActivityHistoryAfterAnswer); the oldest one falls away beyond. */
export const MAX_KEPT_TURNS = 30;
/** Whether the line's step was still at work (queued or running) when the line was told, not finished or failed. */
export const isStepStillRunning = (line) => line.state === "queued" || line.state === "running";
export const EMPTY_ACTIVITY_TICKER_QUEUE = {
    shownLines: {},
    trailIds: [],
    currentId: null,
    waitingLines: [],
    lastShownAt: Number.NEGATIVE_INFINITY,
};
/**
 * The element as a ticker line. An element without a sentence keeps the sentence already told for its step and takes
 * its new state: mergeActivityElements drops the started sentence when a step finishes before the narrator described
 * the finish, and the row must then show its check (or its failure) instead of running on. A step never told has
 * nothing to show.
 */
const toTickerLine = (element, toldLine) => {
    const sentence = element.line ?? toldLine?.line;
    if (!sentence)
        return null;
    return {
        toolCallMessageId: element.toolCallMessageId,
        state: element.state ?? "queued",
        line: sentence,
        kind: element.kind ?? null,
        helperIndex: element.helperIndex ?? null,
    };
};
const isSameLine = (shown, incoming) => !!shown && shown.state === incoming.state && shown.line === incoming.line;
const LINE_STATE_RANK = { queued: 0, running: 1, finished: 2, failed: 2, skipped: 2 };
/** Whether the incoming line is of an earlier state than the one already told for its step (a stale read). */
const isOlderStateThan = (told, incoming) => !!told && (LINE_STATE_RANK[incoming.state] ?? 0) < (LINE_STATE_RANK[told.state] ?? 0);
/** Every shown line in the order it was told, oldest first. */
export const shownLinesInOrder = (queue) => [...queue.trailIds, ...(queue.currentId !== null ? [queue.currentId] : [])]
    .map((stepId) => queue.shownLines[stepId])
    .filter(Boolean);
/**
 * Put one line on screen. A line of a step already told replaces that step's line in place (the history keeps every
 * step once); any other line becomes the newest one, beside the face, and the previous newest one moves up into the
 * trail.
 */
export const showActivityLine = (queue, line, now) => {
    const stepId = line.toolCallMessageId;
    const shownLines = { ...queue.shownLines, [stepId]: line };
    if (queue.currentId === stepId) {
        return { ...queue, shownLines, lastShownAt: now };
    }
    if (queue.trailIds.includes(stepId)) {
        return { ...queue, shownLines };
    }
    const trailIds = queue.currentId !== null ? [...queue.trailIds, queue.currentId] : queue.trailIds;
    return { ...queue, shownLines, trailIds, currentId: stepId, lastShownAt: now };
};
/**
 * Take the endpoint's elements in: every line not shown yet waits in the queue, except the finished line of a step
 * already in the trail, which replaces that row in place at once. Skipped elements and elements of a step never told
 * a sentence never show (only generated sentences appear).
 */
export const receiveActivityElements = (queue, elements, now) => {
    let nextQueue = queue;
    visibleActivityElements(elements).forEach((element) => {
        const stepId = element.toolCallMessageId;
        const waitingLine = nextQueue.waitingLines.find((line) => line.toolCallMessageId === stepId);
        const incomingLine = toTickerLine(element, waitingLine ?? nextQueue.shownLines[stepId]);
        if (!incomingLine)
            return;
        const toldLine = waitingLine ?? nextQueue.shownLines[stepId];
        // per step the later state wins: a read that arrives out of order never moves a told step back
        if (isSameLine(toldLine, incomingLine) || isOlderStateThan(toldLine, incomingLine)) {
            return;
        }
        if (nextQueue.trailIds.includes(stepId)) {
            nextQueue = {
                ...nextQueue,
                shownLines: { ...nextQueue.shownLines, [stepId]: incomingLine },
                waitingLines: nextQueue.waitingLines.filter((line) => line.toolCallMessageId !== stepId),
            };
            return;
        }
        nextQueue = {
            ...nextQueue,
            waitingLines: waitingLine
                ? nextQueue.waitingLines.map((line) => (line.toolCallMessageId === stepId ? incomingLine : line))
                : [...nextQueue.waitingLines, incomingLine],
        };
    });
    while (nextQueue.waitingLines.length > MAX_WAITING_LINES) {
        const [oldestWaitingLine, ...laterWaitingLines] = nextQueue.waitingLines;
        nextQueue = showActivityLine({ ...nextQueue, waitingLines: laterWaitingLines }, oldestWaitingLine, now);
    }
    return nextQueue;
};
/**
 * Milliseconds until the next waiting line may be shown; null when nothing waits. The paces default to
 * {@link LINE_PACE_MS} and {@link BACKLOG_LINE_PACE_MS} (a host sets its own through the chat's `activityTiming`).
 */
export const msUntilNextActivityLine = (queue, now, linePaceMs = LINE_PACE_MS, backlogLinePaceMs = BACKLOG_LINE_PACE_MS) => {
    if (queue.waitingLines.length === 0)
        return null;
    const pace = queue.waitingLines.length > BACKLOG_THRESHOLD ? backlogLinePaceMs : linePaceMs;
    return Math.max(0, queue.lastShownAt + pace - now);
};
/** Show the oldest waiting line. The caller's timer decides when (see {@link msUntilNextActivityLine}). */
export const releaseNextActivityLine = (queue, now) => {
    if (queue.waitingLines.length === 0)
        return queue;
    const [oldestWaitingLine, ...laterWaitingLines] = queue.waitingLines;
    return showActivityLine({ ...queue, waitingLines: laterWaitingLines }, oldestWaitingLine, now);
};
/** Show every waiting line at once, oldest first — the turn has ended and the answer is on screen. */
export const releaseAllActivityLines = (queue, now) => {
    const queueWithoutWaitingLines = { ...queue, waitingLines: [] };
    return queue.waitingLines.reduce((nextQueue, waitingLine) => showActivityLine(nextQueue, waitingLine, now), queueWithoutWaitingLines);
};
/** Steps the turn has finished or failed, the count the ticker reports. */
export const countFinishedSteps = (elements) => visibleActivityElements(elements).filter((element) => element.state === "finished" || element.state === "failed")
    .length;
/**
 * Delegation helpers with a step queued or running right now; a helper running several tool calls counts once. The
 * helper number restarts at 1 for every delegate call, so a helper is told apart by the delegate call its steps follow
 * (the endpoint lists each delegate call's helper steps right after it).
 */
export const countWorkingHelpers = (elements) => {
    const workingHelperKeys = new Set();
    let delegateCallId = null;
    elements.forEach((element) => {
        if (element.helperIndex == null) {
            if (element.kind === "delegation" || element.kind === "background")
                delegateCallId = element.toolCallMessageId;
            return;
        }
        if (element.state === "queued" || element.state === "running") {
            workingHelperKeys.add(`${delegateCallId}:${element.helperIndex}`);
        }
    });
    return workingHelperKeys.size;
};
/** "0:42" / "2:04" — elapsed time as a clock, no unit to translate. */
export const formatElapsedClock = (elapsedMs) => {
    const elapsedSeconds = Math.max(0, Math.floor(elapsedMs / 1000));
    return `${Math.floor(elapsedSeconds / 60)}:${String(elapsedSeconds % 60).padStart(2, "0")}`;
};
export const INITIAL_ACTIVITY_TICKER_STATE = {
    queue: EMPTY_ACTIVITY_TICKER_QUEUE,
    phase: "idle",
    turnMessageId: null,
    liveSince: null,
    keptTurns: [],
    wasRunning: false,
};
/**
 * Whether a read belongs to another turn than the one on screen: the endpoint names a different turn message, or a
 * turn starts after the previous one left the live phase and its message is not named yet (the read hook unpins the
 * turn on a new start, so a new turn arrives unnamed) or named differently. A read that merely misses a step already
 * shown — a read of fewer rows, an answer that overtook a later one — is the same turn: its lines stay.
 */
const isReadOfAnotherTurn = (state, turnMessageId, turnStarted) => {
    if (state.turnMessageId !== null && turnMessageId !== null && turnMessageId !== state.turnMessageId)
        return true;
    return turnStarted && state.phase !== "live" && (turnMessageId === null || turnMessageId !== state.turnMessageId);
};
/**
 * The kept turns plus the turn in its success beat, if it can be placed in the thread (the endpoint named the message
 * that started it). A turn kept before under the same message is replaced: its helpers ended and it ended again.
 */
const keptTurnsWithFinishedTurn = (state) => {
    const { phase, turnMessageId, queue, keptTurns } = state;
    if (phase !== "done" || turnMessageId === null)
        return keptTurns;
    const finishedTurn = { turnMessageId, lines: shownLinesInOrder(queue) };
    return [...keptTurns.filter((keptTurn) => keptTurn.turnMessageId !== turnMessageId), finishedTurn].slice(-MAX_KEPT_TURNS);
};
/**
 * The ticker's life across turns. A turn starts when `running` rises: the queue starts clean and the phase goes live.
 * Within a turn the endpoint's snapshot only grows (mergeActivityElements keeps every row it has seen), so a shown step
 * missing from the elements means another turn or another conversation: the queue starts clean. When `running` falls,
 * the waiting lines are shown at once and the face's success beat follows — unless the turn failed or never told a
 * line. After the beat the turn's lines either stay in the thread ({@link KeptActivityTurn}) or are gone, as
 * `keepHistory` says.
 */
export const activityTickerReducer = (state, action) => {
    switch (action.type) {
        case "elementsReceived": {
            const { elements, running, failed, keepHistory, now } = action;
            const turnStarted = running && !state.wasRunning;
            const turnEnded = !running && state.wasRunning;
            // Lines already shown in a turn are never dropped: the stack resets only for another turn.
            const startsClean = isReadOfAnotherTurn(state, action.turnMessageId, turnStarted);
            const queue = receiveActivityElements(startsClean ? EMPTY_ACTIVITY_TICKER_QUEUE : state.queue, elements, now);
            let nextState = {
                ...state,
                queue,
                turnMessageId: action.turnMessageId ?? (startsClean ? null : state.turnMessageId),
                wasRunning: running,
            };
            // The turn in its success beat leaves the screen before the next one starts clean: kept in the thread or gone.
            if (startsClean && state.phase === "done") {
                nextState = {
                    ...nextState,
                    phase: "idle",
                    keptTurns: keepHistory ? keptTurnsWithFinishedTurn(state) : state.keptTurns,
                };
            }
            if (running && startsClean) {
                nextState = { ...nextState, phase: "live", liveSince: now };
            }
            if (turnEnded && state.phase === "live") {
                const finalQueue = releaseAllActivityLines(queue, now);
                const toldALine = finalQueue.currentId !== null;
                nextState = { ...nextState, queue: finalQueue, phase: toldALine && !failed ? "done" : "idle" };
            }
            if (failed && nextState.phase === "done") {
                nextState = { ...nextState, phase: "idle" };
            }
            return nextState;
        }
        case "nextLineDue":
            return state.phase === "live" ? { ...state, queue: releaseNextActivityLine(state.queue, action.now) } : state;
        case "doneBeatOver":
            return state.phase === "done"
                ? {
                    ...state,
                    phase: "idle",
                    keptTurns: action.keepHistory ? keptTurnsWithFinishedTurn(state) : state.keptTurns,
                }
                : state;
        default:
            return state;
    }
};
