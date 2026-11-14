import { Dispatch, SetStateAction, useCallback, useEffect, useMemo, useRef } from "react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import { isAgentsClientError } from "../../contract/conversation";
import type { ChatMessage } from "../chat-message";
import type { SendMessageHandler } from "../conversation-send-message";
import { normalizeServerMessageToMarkdown } from "../normalize-server-message-to-markdown";
import type { InjectSignalOptions } from "../../renderer/ConversationWidgetCallbacksContext";
import {
  buildUnrenderedWidgetSignal,
  detectUnrenderedWidgets,
  WIDGET_INVALID_SIGNAL_SOURCE,
} from "../../renderer/detect-unrendered-widgets";
import {
  buildWidgetRenderReportSignal,
  createWidgetRenderReportCollector,
  WIDGET_RENDER_REPORT_SIGNAL_SOURCE,
  type WidgetRenderBlockReport,
} from "../../renderer/render-report/widget-render-report-collector";

/** Max invalid-widget corrective signals per conversation — a too-weak model that keeps emitting bad widgets stops here. */
const WIDGET_CORRECTION_CAP = 2;

export interface ConversationSignalsInputs {
  /** The conversation client the signals go through (`injectSignal`). */
  client: AgentsConversationClient;
  /** The conversation the host asked for (the chat's `conversationId` prop); a signal targets it while none is loaded. */
  conversationId: number | undefined;
  /** The conversation the chat store holds right now. */
  conversation: { id?: number | null } | null;
  /** The loaded messages; the newest settled assistant message is checked for a dropped widget block. */
  messages: ChatMessage[] | null;
  /** The signal's run status ("running" | "idle" | "failed" | null). */
  runStatus: string | null;
  /** A start is in flight; a signal is not sent into a conversation that does not exist yet then. */
  startingNewConversation: boolean;
  /** The run is over; the backend refuses further input. */
  isConversationRunEnded: boolean;
  /** The chat's send — a signal without a conversation starts one through it. */
  handleSendMessage: SendMessageHandler;
  /** Shows the closed-conversation refusal on the error card. */
  rejectAsConversationClosed: () => void;
  /** Re-reads the conversation's metadata (the closed run state locks the widget controls). */
  refetchConversationSummary: () => void;
  /** The chat's jump-to-bottom request counter; a customer's click follows the turn it starts. */
  setScrollToBottomNonce: Dispatch<SetStateAction<number>>;
}

export interface ConversationSignals {
  /** Injects a model-visible, customer-invisible signal into the current conversation (the widget context's `injectSignal`). */
  handleInjectSignal: (signalText: string, options?: InjectSignalOptions) => Promise<boolean>;
  /** The widget render report collector of the current conversation (one per conversation). */
  renderReportCollector: ReturnType<typeof createWidgetRenderReportCollector>;
}

/**
 * The signals the chat sends besides the customer's messages: a widget's injected signal (with the closed-conversation
 * guard and the cold start through a START signal), a context-only signal parked until a turn is observed running,
 * the corrective signal for a widget block the renderer dropped, and the widget render report.
 */
export const useConversationSignals = (inputs: ConversationSignalsInputs): ConversationSignals => {
  const {
    client,
    conversationId,
    conversation,
    messages,
    runStatus,
    startingNewConversation,
    isConversationRunEnded,
    handleSendMessage,
    rejectAsConversationClosed,
    refetchConversationSummary,
    setScrollToBottomNonce,
  } = inputs;
  // The conversation actually shown right now, kept in sync every render — read from an `injectSignal` `.catch`
  // so a late-landing rejection for a conversation the customer already left doesn't dispatch onto whatever is
  // open now (see the id check inside `handleInjectSignal`).
  const currentConversationIdRef = useRef<number | null>(null);
  currentConversationIdRef.current = conversation?.id ?? null;

  // A `withoutOwnAgentTurn` signal (context riding along a just-posted user message, e.g. advisor-extension
  // instructions) waiting for a turn to be OBSERVED running. Delivered mid-turn it lands in the conversation history
  // without re-driving the agent — the backend only re-drives when the LAST message is an unanswered inbound, and a
  // signal appended before the live turn's reply is no longer last (see the flush effect's strand explanation below;
  // here that strand behavior is exactly what we want: in context from the next turn on, never its own reply).
  // `conversationId` pins the signal to the conversation open at park time: null = parked on a cold start (the
  // paired message creates the conversation, deliver into whichever id lands); a non-null mismatch at flush time
  // means the user switched conversations first — the signal is dropped, never leaked into the other conversation.
  const signalAwaitingRunningTurnRef = useRef<{ signalText: string; conversationId: number | null } | null>(null);
  // Inject a model-visible / user-invisible SIGNAL into the CURRENT conversation and drive one agent turn — the
  // data-back path for a picker widget ("User selected image A"). Resilient (swallows errors, never throws
  // into render). The reply lands via the existing realtime signal, exactly like a posted message — no extra handling
  // needed here.
  //
  // NOTE: unlike the message-post path this carries NO admin overrides. The endpoint
  // (AIConversationsController::injectSignal) reads only `signalText` + `signalSource` — it has no `budgetBypass` /
  // `aiModelName` parameter, so the earlier hand-wired call was sending two query params the backend discarded. An
  // impersonating admin's model pick therefore does not apply to a widget-click-driven turn; the conversation's
  // stored model does.
  const handleInjectSignal = useCallback(
    async (signalText: string, options?: InjectSignalOptions): Promise<boolean> => {
      if (!signalText.trim()) return false;
      // CUSTOMER_CLICK (the default when options is omitted, e.g. the briefing widgets' plain signal text) is the
      // customer pressing a widget button; PROGRAMMATIC (park-then-flush ride-alongs, the widget-invalid corrective)
      // never came from a click — it must never surface a refusal or move the scroll position.
      const isCustomerClick = options?.signalOrigin !== "PROGRAMMATIC";
      // Same guard as the message path: a closed conversation rejects signals too, so no widget click posts one. A
      // genuine click gets the reason on the error card; a programmatic ride-along is simply dropped, as on any
      // other failure.
      if (isConversationRunEnded) {
        if (isCustomerClick) {
          rejectAsConversationClosed();
        }
        return false;
      }
      // On a real click, scroll the chat to the bottom to follow the turn this signal drives.
      if (isCustomerClick) {
        setScrollToBottomNonce((nonce) => nonce + 1);
      }
      // Context-only signal: deliver it ONLY while a turn is running, so it never drives its own turn / reply. Not
      // running yet (the paired user message's POST is still in flight, or the conversation is still being created on
      // a cold start) — park it until the flush effect below sees "running". Dropped if no turn ever starts.
      if (options?.withoutOwnAgentTurn && runStatus !== "running") {
        signalAwaitingRunningTurnRef.current = {
          signalText,
          conversationId: conversation?.id ?? conversationId ?? null,
        };
        return false;
      }
      const targetConversationId = conversation?.id ?? conversationId;
      // No conversation yet (e.g. a briefing CTA clicked on the cold dashboard greeting): start one via the
      // start-with-signal path — an EMPTY visible message carrying the click as the invisible START signal, so the
      // agent's first turn already has it and it speaks first (no visible bubble, no deferred re-inject). Once a
      // conversation exists we keep the silent injectSignal behavior below.
      if (!targetConversationId) {
        if (startingNewConversation) return false;
        handleSendMessage("", { startSignalText: signalText });
        return false;
      }
      // No admin-debug overrides here on purpose — see the NOTE on this callback: the endpoint takes neither
      // `budgetBypass` nor `aiModelName`.
      // signalSource defaults server-side to FRONTEND_WIDGET_CLICK; the widget-failover passes FRONTEND_WIDGET_INVALID.
      return client
        .injectSignal(targetConversationId, { text: signalText, source: options?.signalSource })
        .then(() => true)
        .catch((signalRequestError: unknown) => {
          // Best-effort: a failed signal must not break the widget render. The turn simply doesn't advance. A
          // GENUINE widget click is the exception, and only for the one refusal reason the customer can act on
          // (the conversation closed meanwhile) — every other failure stays silent, exactly as before.
          const isConversationClosedRefusal =
            isAgentsClientError(signalRequestError) && signalRequestError.kind === "closed";
          // The request targeted a conversation the customer has since left (switched conversations before this
          // late-landing rejection arrived) — don't dispatch onto whatever conversation is open now.
          if (
            isCustomerClick &&
            isConversationClosedRefusal &&
            currentConversationIdRef.current === targetConversationId
          ) {
            rejectAsConversationClosed();
            // The closed runState locks the remaining widget controls.
            refetchConversationSummary();
          }
          return false;
        });
    },
    [
      client,
      conversation,
      conversationId,
      startingNewConversation,
      handleSendMessage,
      runStatus,
      isConversationRunEnded,
      rejectAsConversationClosed,
      refetchConversationSummary,
      setScrollToBottomNonce,
    ],
  );

  // Deliver a parked context-only signal the moment its paired turn is observed running (see
  // `signalAwaitingRunningTurnRef`). Re-entering handleInjectSignal with `withoutOwnAgentTurn` while "running"
  // passes its park-guard and POSTs. Tiny race stays open: if the turn settles between this observation and the
  // POST landing, the signal becomes the last message and drives one extra turn — rare and harmless (the agent
  // acknowledges the instructions), accepted for an FE-only build.
  useEffect(() => {
    if (runStatus !== "running" || !signalAwaitingRunningTurnRef.current) return;
    const parkedSignal = signalAwaitingRunningTurnRef.current;
    signalAwaitingRunningTurnRef.current = null;
    // Parked for a DIFFERENT conversation (the user switched before its turn ran) — drop, don't leak it here.
    if (parkedSignal.conversationId !== null && parkedSignal.conversationId !== conversation?.id) return;
    handleInjectSignal(parkedSignal.signalText, { withoutOwnAgentTurn: true, signalOrigin: "PROGRAMMATIC" });
  }, [runStatus, handleInjectSignal, conversation?.id]);

  // ── Widget failover ─────────────────────────────────────────────────────────────────────────────────────────────
  // A weaker model can emit a ```widget block whose `type` is not a real widget (a model wrote
  // `type: "example"`, not `ExampleWidget`). remarkWidgetFencePlugin silently DROPS it → the customer sees no
  // widget and the model gets no feedback, so it insists "it should be visible". Detect the dropped block on the newest
  // SETTLED assistant message and inject a silent corrective SIGNAL (with a similarity "did you mean" shortlist) so the
  // model re-emits with a valid name. Guards: (1) only after a live turn we OBSERVED running — never retroactively
  // "correct" widgets already in history when a conversation opens; (2) report each message id at most once; (3) cap
  // corrections per conversation so a too-weak model can't loop forever.
  const sawRunningForWidgetCheckRef = useRef(false);
  const reportedWidgetErrorMessageIdsRef = useRef<Set<number>>(new Set());
  const widgetCorrectionCountRef = useRef(0);

  useEffect(() => {
    if (runStatus === "running") sawRunningForWidgetCheckRef.current = true;
  }, [runStatus]);

  useEffect(() => {
    // A new conversation resets the live-turn sighting and the per-conversation correction budget. (The parked
    // context-only signal is NOT cleared here: a cold start legitimately transitions id null→new while the signal
    // waits; cross-conversation leaking is prevented by the id check in its flush effect instead.)
    sawRunningForWidgetCheckRef.current = false;
    reportedWidgetErrorMessageIdsRef.current = new Set();
    widgetCorrectionCountRef.current = 0;
  }, [conversation?.id]);

  useEffect(() => {
    if (runStatus !== "idle" || !sawRunningForWidgetCheckRef.current) return;
    if (widgetCorrectionCountRef.current >= WIDGET_CORRECTION_CAP || !messages) return;
    const latestAssistantMessage = [...messages]
      .reverse()
      .find(
        (message) => message.role === "ASSISTANT" && message.messageType === "TEXT",
      );
    const messageId = latestAssistantMessage?.id;
    if (!messageId || !latestAssistantMessage?.message) return;
    if (reportedWidgetErrorMessageIdsRef.current.has(messageId)) return;
    // Feed the SAME normalized text ConversationMarkdown renders (it repairs a fence the model broke mid-text) —
    // scanning the raw stored text here would report a fence the renderer already fixed as unrendered.
    const unrenderedWidgets = detectUnrenderedWidgets(normalizeServerMessageToMarkdown(latestAssistantMessage.message));
    if (unrenderedWidgets.length === 0) return;
    reportedWidgetErrorMessageIdsRef.current.add(messageId);
    widgetCorrectionCountRef.current += 1;
    handleInjectSignal(buildUnrenderedWidgetSignal(unrenderedWidgets), {
      signalSource: WIDGET_INVALID_SIGNAL_SOURCE,
      signalOrigin: "PROGRAMMATIC",
    });
  }, [messages, runStatus, handleInjectSignal]);

  // ── Widget render report ─────────────────────────────────────────────────────────────────────────
  // Every block of a live-delivered message reports mounted / empty / failed / pending; once a message's blocks settle,
  // ONE context signal tells the model which ones the owner did not see as intended. A clean render sends nothing; the
  // source owes no work, so the signal never drives a turn of its own (the next turn reads it). One collector per
  // conversation, so a switch never reports into the other one.
  const sendWidgetRenderReportRef = useRef<(messageId: string, blocks: WidgetRenderBlockReport[]) => void>(() => {});
  sendWidgetRenderReportRef.current = (messageId: string, blocks: WidgetRenderBlockReport[]) => {
    const renderReportSignalText = buildWidgetRenderReportSignal(messageId, blocks);
    if (renderReportSignalText === null) return;
    handleInjectSignal(renderReportSignalText, {
      signalSource: WIDGET_RENDER_REPORT_SIGNAL_SOURCE,
      signalOrigin: "PROGRAMMATIC",
    });
  };
  const renderReportCollector = useMemo(
    () =>
      createWidgetRenderReportCollector({
        onSettled: (messageId, blocks) => sendWidgetRenderReportRef.current(messageId, blocks),
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- one collector per conversation
    [conversation?.id],
  );
  useEffect(() => () => renderReportCollector.dispose(), [renderReportCollector]);

  return { handleInjectSignal, renderReportCollector };
};
