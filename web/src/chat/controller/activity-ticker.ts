import { ActivityElement, visibleActivityElements } from "./merge-activity-elements";

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

/** One sentence the ticker shows: the started or the finished line of one step. */
export interface ActivityTickerLine {
  toolCallMessageId: number;
  /** The step's state when this line was told: queued / running / finished / failed. */
  state: string;
  line: string;
  kind: string | null;
  /** The delegation helper that ran the step (1-based); null for the assistant's own steps. */
  helperIndex: number | null;
}

/** Whether the line's step was still at work (queued or running) when the line was told, not finished or failed. */
export const isStepStillRunning = (line: ActivityTickerLine): boolean =>
  line.state === "queued" || line.state === "running";

/**
 * What the ticker shows and what still waits to be shown. The endpoint delivers lines in bursts (one request per 3 s,
 * up to 8 lines per narrator answer); this queue hands them to the screen one at a time.
 */
export interface ActivityTickerQueue {
  /** The line each step shows right now, keyed by its tool-call message id. */
  shownLines: Record<number, ActivityTickerLine>;
  /** The steps shown before the newest one, oldest first; never contains {@link currentId}. */
  trailIds: number[];
  /** The step whose line was shown last: the one beside the face. */
  currentId: number | null;
  /** Lines not shown yet, oldest first, at most one per step (a newer line of the same step replaces the waiting one). */
  waitingLines: ActivityTickerLine[];
  /** When the newest line last changed (ms since epoch); the pace counts from here. */
  lastShownAt: number;
}

export const EMPTY_ACTIVITY_TICKER_QUEUE: ActivityTickerQueue = {
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
const toTickerLine = (
  element: ActivityElement,
  toldLine: ActivityTickerLine | undefined,
): ActivityTickerLine | null => {
  const sentence = element.line ?? toldLine?.line;
  if (!sentence) return null;
  return {
    toolCallMessageId: element.toolCallMessageId,
    state: element.state ?? "queued",
    line: sentence,
    kind: element.kind ?? null,
    helperIndex: element.helperIndex ?? null,
  };
};

const isSameLine = (shown: ActivityTickerLine | undefined, incoming: ActivityTickerLine) =>
  !!shown && shown.state === incoming.state && shown.line === incoming.line;

/** Every shown line in the order it was told, oldest first. */
export const shownLinesInOrder = (queue: ActivityTickerQueue): ActivityTickerLine[] =>
  [...queue.trailIds, ...(queue.currentId !== null ? [queue.currentId] : [])]
    .map((stepId) => queue.shownLines[stepId])
    .filter(Boolean);

/**
 * Put one line on screen. A line of a step already told replaces that step's line in place (the history keeps every
 * step once); any other line becomes the newest one, beside the face, and the previous newest one moves up into the
 * trail.
 */
export const showActivityLine = (
  queue: ActivityTickerQueue,
  line: ActivityTickerLine,
  now: number,
): ActivityTickerQueue => {
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
export const receiveActivityElements = (
  queue: ActivityTickerQueue,
  elements: ActivityElement[],
  now: number,
): ActivityTickerQueue => {
  let nextQueue = queue;
  visibleActivityElements(elements).forEach((element) => {
    const stepId = element.toolCallMessageId;
    const waitingLine = nextQueue.waitingLines.find((line) => line.toolCallMessageId === stepId);
    const incomingLine = toTickerLine(element, waitingLine ?? nextQueue.shownLines[stepId]);
    if (!incomingLine) return;
    if (waitingLine ? isSameLine(waitingLine, incomingLine) : isSameLine(nextQueue.shownLines[stepId], incomingLine)) {
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

/** Milliseconds until the next waiting line may be shown; null when nothing waits. */
export const msUntilNextActivityLine = (queue: ActivityTickerQueue, now: number): number | null => {
  if (queue.waitingLines.length === 0) return null;
  const pace = queue.waitingLines.length > BACKLOG_THRESHOLD ? BACKLOG_LINE_PACE_MS : LINE_PACE_MS;
  return Math.max(0, queue.lastShownAt + pace - now);
};

/** Show the oldest waiting line. The caller's timer decides when (see {@link msUntilNextActivityLine}). */
export const releaseNextActivityLine = (queue: ActivityTickerQueue, now: number): ActivityTickerQueue => {
  if (queue.waitingLines.length === 0) return queue;
  const [oldestWaitingLine, ...laterWaitingLines] = queue.waitingLines;
  return showActivityLine({ ...queue, waitingLines: laterWaitingLines }, oldestWaitingLine, now);
};

/** Show every waiting line at once, oldest first — the turn has ended and the answer is on screen. */
export const releaseAllActivityLines = (queue: ActivityTickerQueue, now: number): ActivityTickerQueue => {
  const queueWithoutWaitingLines: ActivityTickerQueue = { ...queue, waitingLines: [] };
  return queue.waitingLines.reduce(
    (nextQueue, waitingLine) => showActivityLine(nextQueue, waitingLine, now),
    queueWithoutWaitingLines,
  );
};

/** Steps the turn has finished or failed, the count the ticker reports. */
export const countFinishedSteps = (elements: ActivityElement[]): number =>
  visibleActivityElements(elements).filter((element) => element.state === "finished" || element.state === "failed")
    .length;

/**
 * Delegation helpers with a step queued or running right now; a helper running several tool calls counts once. The
 * helper number restarts at 1 for every delegate call, so a helper is told apart by the delegate call its steps follow
 * (the endpoint lists each delegate call's helper steps right after it).
 */
export const countWorkingHelpers = (elements: ActivityElement[]): number => {
  const workingHelperKeys = new Set<string>();
  let delegateCallId: number | null = null;
  elements.forEach((element) => {
    if (element.helperIndex == null) {
      if (element.kind === "delegation" || element.kind === "background") delegateCallId = element.toolCallMessageId;
      return;
    }
    if (element.state === "queued" || element.state === "running") {
      workingHelperKeys.add(`${delegateCallId}:${element.helperIndex}`);
    }
  });
  return workingHelperKeys.size;
};

/** "0:42" / "2:04" — elapsed time as a clock, no unit to translate. */
export const formatElapsedClock = (elapsedMs: number): string => {
  const elapsedSeconds = Math.max(0, Math.floor(elapsedMs / 1000));
  return `${Math.floor(elapsedSeconds / 60)}:${String(elapsedSeconds % 60).padStart(2, "0")}`;
};

/** Nothing on screen · lines beside the face while the turn runs · the face's success beat after it ended. */
export type ActivityTickerPhase = "idle" | "live" | "done";

/** A finished turn whose lines stay in the thread, above its answer, under the message that started it. */
export interface KeptActivityTurn {
  turnMessageId: number;
  /** Every line the turn told, oldest first. */
  lines: ActivityTickerLine[];
}

export interface ActivityTickerState {
  queue: ActivityTickerQueue;
  phase: ActivityTickerPhase;
  /** The message that started the turn on screen, as the endpoint named it; null until it did. */
  turnMessageId: number | null;
  /** When the running turn started on this screen (ms since epoch), for the elapsed clock; null before any turn. */
  liveSince: number | null;
  /** Finished turns that stay in the thread, oldest first; filled only while the history is kept. */
  keptTurns: KeptActivityTurn[];
  /** The `running` of the previous input, to see where a turn starts and ends. */
  wasRunning: boolean;
}

export const INITIAL_ACTIVITY_TICKER_STATE: ActivityTickerState = {
  queue: EMPTY_ACTIVITY_TICKER_QUEUE,
  phase: "idle",
  turnMessageId: null,
  liveSince: null,
  keptTurns: [],
  wasRunning: false,
};

export type ActivityTickerAction =
  | {
      type: "elementsReceived";
      elements: ActivityElement[];
      running: boolean;
      failed: boolean;
      turnMessageId: number | null;
      /** A turn in its success beat that the next turn replaces goes into {@link ActivityTickerState.keptTurns} first. */
      keepHistory: boolean;
      now: number;
    }
  | { type: "nextLineDue"; now: number }
  | { type: "doneBeatOver"; keepHistory: boolean };

const hasShownStepsMissingFrom = (queue: ActivityTickerQueue, elements: ActivityElement[]) => {
  const elementIds = new Set(elements.map((element) => element.toolCallMessageId));
  return (
    Object.keys(queue.shownLines).some((stepId) => !elementIds.has(Number(stepId))) ||
    queue.waitingLines.some((line) => !elementIds.has(line.toolCallMessageId))
  );
};

/**
 * The kept turns plus the turn in its success beat, if it can be placed in the thread (the endpoint named the message
 * that started it). A turn kept before under the same message is replaced: its helpers ended and it ended again.
 */
const keptTurnsWithFinishedTurn = (state: ActivityTickerState): KeptActivityTurn[] => {
  const { phase, turnMessageId, queue, keptTurns } = state;
  if (phase !== "done" || turnMessageId === null) return keptTurns;
  const finishedTurn: KeptActivityTurn = { turnMessageId, lines: shownLinesInOrder(queue) };
  return [...keptTurns.filter((keptTurn) => keptTurn.turnMessageId !== turnMessageId), finishedTurn].slice(
    -MAX_KEPT_TURNS,
  );
};

/**
 * The ticker's life across turns. A turn starts when `running` rises: the queue starts clean and the phase goes live.
 * Within a turn the endpoint's snapshot only grows (mergeActivityElements keeps every row it has seen), so a shown step
 * missing from the elements means another turn or another conversation: the queue starts clean. When `running` falls,
 * the waiting lines are shown at once and the face's success beat follows — unless the turn failed or never told a
 * line. After the beat the turn's lines either stay in the thread ({@link KeptActivityTurn}) or are gone, as
 * `keepHistory` says.
 */
export const activityTickerReducer = (
  state: ActivityTickerState,
  action: ActivityTickerAction,
): ActivityTickerState => {
  switch (action.type) {
    case "elementsReceived": {
      const { elements, running, failed, keepHistory, now } = action;
      const turnStarted = running && !state.wasRunning;
      const turnEnded = !running && state.wasRunning;
      const startsClean = turnStarted || hasShownStepsMissingFrom(state.queue, elements);
      const queue = receiveActivityElements(startsClean ? EMPTY_ACTIVITY_TICKER_QUEUE : state.queue, elements, now);
      let nextState: ActivityTickerState = {
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
