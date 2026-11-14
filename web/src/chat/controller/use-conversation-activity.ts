import { useCallback, useEffect, useRef, useState } from "react";

import { useAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import { ActivityElement, isDifferentTurn, mergeActivityElements } from "./merge-activity-elements";

/** At most one activity request per this many milliseconds per conversation, whatever the tick rate. */
export const ACTIVITY_REQUEST_WINDOW_MS = 3000;
/** While a turn runs, a request also fires on this cadence: the floor for a dead signal. */
export const RUNNING_FLOOR_MS = 4000;
/**
 * Once the signal no longer says "running", this many failed requests in a row end the turn on screen: an endpoint that
 * keeps failing must not keep the face working and ask again every {@link RUNNING_FLOOR_MS} while the chat is open.
 */
export const MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED = 3;

type Params = {
  conversationId: number;
  /** The signal's run status: "running" while a turn drives, "idle" / "failed" when it ended, null before the first read. */
  runStatus: string | null;
  /** Advances on every signal tick the host acts on (its message refetch); each advance asks for the activity too. */
  signalVersion: number;
  enabled?: boolean;
};

type Result = {
  /** The rows of the stack in row order; empty before the first turn of this mount. */
  elements: ActivityElement[];
  /** Whether the narrated turn is still in flight. */
  running: boolean;
  /** The message that started the narrated turn, as the endpoint named it; null before its first answer. */
  turnMessageId: number | null;
};

/**
 * What the agent is doing for the customer right now: pulls the conversation client's
 * `getConversationActivity` (`AgentsConversationClientProvider`) while the customer watches a running turn and merges the answer into the stack. Frontend-driven on purpose: nothing is
 * generated for a hidden tab or a closed chat. The turn is pinned when it starts (`turnMessageId` from the first
 * answer), so a message the customer sends mid-turn does not move the window. Debounced to one request per
 * {@link ACTIVITY_REQUEST_WINDOW_MS} with one trailing request after a burst; a terminal status fires one request for
 * the finished lines, and the requests go on until the endpoint answers that the turn ended — then the stack rests
 * until the next turn. A failed run ends the turn with that last request, whatever it answers, and so do
 * {@link MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED} failed requests in a row once the signal stopped saying "running".
 */
export const useConversationActivity = ({
  conversationId,
  runStatus,
  signalVersion,
  enabled = true,
}: Params): Result => {
  const conversationClient = useAgentsConversationClient();
  const [elements, setElements] = useState<ActivityElement[]>([]);
  const [running, setRunning] = useState(false);
  const [turnMessageId, setTurnMessageId] = useState<number | null>(null);
  const turnMessageIdRef = useRef<number | null>(null);
  const elementsRef = useRef<ActivityElement[]>([]);
  const inFlightRef = useRef(false);
  const trailingRef = useRef(false);
  const lastRequestAtRef = useRef(0);
  const trailingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const runningRef = useRef(false);
  // What the endpoint said last about the pinned turn: still running or ended. The signal reports the parent
  // conversation IDLE while its helpers run, so a signal "running" after "idle" is not by itself a new turn.
  const answeredRunningRef = useRef(false);
  // The signal's run status as the answers read it: they land after the status may have changed.
  const runStatusRef = useRef<string | null>(runStatus);
  const consecutiveFailedRequestsRef = useRef(0);
  // The throttled entry, reachable from the request's own trailing call without a dependency cycle.
  const requestActivityRef = useRef<((immediate?: boolean) => void) | null>(null);

  useEffect(() => {
    elementsRef.current = elements;
  }, [elements]);

  const performRequest = useCallback(async () => {
    if (!enabled || !Number.isFinite(conversationId) || conversationId <= 0) return;
    if (inFlightRef.current) {
      trailingRef.current = true;
      return;
    }
    inFlightRef.current = true;
    lastRequestAtRef.current = Date.now();
    try {
      const response = await conversationClient.getConversationActivity(conversationId, turnMessageIdRef.current);
      const answeredTurn = response.turnMessageId;
      // A new turn started since the stack was pinned: the rows of the old turn fall away, the new turn starts clean.
      const newTurn = isDifferentTurn(turnMessageIdRef.current, answeredTurn);
      turnMessageIdRef.current = answeredTurn;
      setTurnMessageId(answeredTurn);
      const merged = mergeActivityElements(newTurn ? [] : elementsRef.current, response.elements);
      elementsRef.current = merged;
      setElements(merged);
      consecutiveFailedRequestsRef.current = 0;
      // A failed run has ended, whatever its steps say: a tool call the failure left open answers "running" forever.
      answeredRunningRef.current = response.turnRunning && runStatusRef.current !== "failed";
      setRunning(answeredRunningRef.current);
    } catch {
      // best-effort: the states of the next answer replace whatever is missing now — unless the turn is known to have
      // ended (the run failed) or the endpoint keeps failing after the signal stopped saying "running".
      consecutiveFailedRequestsRef.current += 1;
      if (
        runStatusRef.current === "failed" ||
        (runStatusRef.current !== "running" &&
          consecutiveFailedRequestsRef.current >= MAX_FAILED_REQUESTS_AFTER_SIGNAL_ENDED)
      ) {
        answeredRunningRef.current = false;
        setRunning(false);
      }
    } finally {
      inFlightRef.current = false;
      if (trailingRef.current) {
        trailingRef.current = false;
        requestActivityRef.current?.();
      }
    }
  }, [conversationId, conversationClient, enabled]);

  // The one throttle every trigger goes through: at most one request per window, one trailing call for a burst.
  const requestActivity = useCallback(
    (immediate = false) => {
      if (typeof document !== "undefined" && document.hidden) return;
      if (immediate) {
        if (trailingTimerRef.current !== null) {
          clearTimeout(trailingTimerRef.current);
          trailingTimerRef.current = null;
        }
        void performRequest();
        return;
      }
      const sinceLast = Date.now() - lastRequestAtRef.current;
      if (sinceLast >= ACTIVITY_REQUEST_WINDOW_MS) {
        void performRequest();
        return;
      }
      if (trailingTimerRef.current === null) {
        trailingTimerRef.current = setTimeout(() => {
          trailingTimerRef.current = null;
          void performRequest();
        }, ACTIVITY_REQUEST_WINDOW_MS - sinceLast);
      }
    },
    [performRequest],
  );
  useEffect(() => {
    requestActivityRef.current = requestActivity;
  }, [requestActivity]);

  // Turn start: drop whatever the previous (already rested) turn left on screen BEFORE asking again — otherwise the
  // first response for the new turn would merge its rows into the old ones still sitting in state (the bug: sending
  // a new message while the previous turn's stack is still shown left the old rows in place with the new ones
  // appended). Unpin, clear the stack, mark it running so the UI switches to the live ticker at once, then request.
  // Turn end: one last request for the finished lines (the response's own `turnRunning` flips `running` false once
  // that lands, not this effect — so the ticker keeps whatever it has until the terminal answer is in).
  useEffect(() => {
    runStatusRef.current = runStatus;
    const isRunning = runStatus === "running";
    const wasRunning = runningRef.current;
    runningRef.current = isRunning;
    if (isRunning && !wasRunning) {
      consecutiveFailedRequestsRef.current = 0;
      // A fresh turn only when the endpoint said the pinned one had ended; while helpers run the parent's signal
      // flips idle and back, and the ticker must keep its rows (a different answered turn resets it anyway).
      if (!answeredRunningRef.current) {
        turnMessageIdRef.current = null;
        setTurnMessageId(null);
        elementsRef.current = [];
        setElements([]);
      }
      setRunning(true);
      requestActivity(true);
    } else if (!isRunning && wasRunning) {
      requestActivity(true);
    }
  }, [runStatus, requestActivity]);

  // Every signal tick the host acts on asks for the activity too (throttled above).
  useEffect(() => {
    if (signalVersion > 0 && runningRef.current) {
      requestActivity();
    }
  }, [signalVersion, requestActivity]);

  // The floor while the turn is in flight (a dead signal, a long tool) and the catch-up on tab return. In flight until
  // the endpoint itself answers that the turn ended, not only while the signal says "running": the signal reports the
  // parent idle while its helpers work, a turn can end while the tab is hidden (the terminal request above is then
  // skipped), and that request can fail. Stopping at the signal would leave `running` true until the next turn. The
  // catch in performRequest bounds it: a failed run, or failed requests in a row, set `running` false.
  useEffect(() => {
    if (!enabled || (runStatus !== "running" && !running)) return;
    const interval = setInterval(() => requestActivity(), RUNNING_FLOOR_MS);
    const onVisibilityChange = () => {
      if (!document.hidden) requestActivity(true);
    };
    document.addEventListener("visibilitychange", onVisibilityChange);
    return () => {
      clearInterval(interval);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, [enabled, runStatus, running, requestActivity]);

  // A different conversation: nothing of the old one is shown.
  useEffect(() => {
    turnMessageIdRef.current = null;
    setTurnMessageId(null);
    elementsRef.current = [];
    setElements([]);
    setRunning(false);
    consecutiveFailedRequestsRef.current = 0;
    return () => {
      if (trailingTimerRef.current !== null) {
        clearTimeout(trailingTimerRef.current);
        trailingTimerRef.current = null;
      }
    };
  }, [conversationId]);

  return { elements, running, turnMessageId };
};
