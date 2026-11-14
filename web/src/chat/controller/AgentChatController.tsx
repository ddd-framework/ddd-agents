import {
  type ComponentType,
  type ReactNode,
  type RefObject,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import type { AgentsConversationClient } from "../../client/AgentsConversationClient";
import {
  AgentsClientError,
  type ConversationAttachmentReference,
  isAgentsClientError,
} from "../../contract/conversation";
import { reportRunningTurn } from "../../provider/environment";
import { CanvasRestoreOnMount } from "../../renderer/canvas/CanvasRestoreOnMount";
import { ConversationDeepLinkedWidget } from "../../renderer/ConversationDeepLinkedWidget";
import { ConversationWidgetProvider } from "../../renderer/ConversationWidgetContext";
import { WidgetRenderReportCollectorContext } from "../../renderer/render-report/widget-render-report-collector";
import { resolveDeepLinkableWidget } from "../../renderer/resolve-deep-linkable-widget";
import type { ChatMessage } from "../chat-message";
import type { SendMessageHandler } from "../conversation-send-message";
import type { ConversationMessageInputHandle } from "../ConversationMessageInput/ConversationMessageInput";
import { ConversationView } from "../ConversationView";
import type { AgentsChatState } from "../store/agents-chat-state";
import { ConversationErrorType } from "../store/conversation-error-type";
import type { AgentsChatStore } from "../store/create-agents-chat-store";
import { useAgentsChatSelector } from "../store/use-agents-chat-store";
import type { ChatScrollTarget } from "./chat-scroll-target";
import { deriveAgentActivity } from "./derive-agent-activity";
import { isChatReadyToSendPendingStartRequest, type PendingStartRequest, pickPendingStartRequest } from "./pending-start-request";
import { type ActivityTicker, type ActivityTickerTurn, useActivityTicker } from "./use-activity-ticker";
import { useConversationActivity } from "./use-conversation-activity";
import { useConversationLiveUpdates } from "./use-conversation-live-updates";
import { CONVERSATION_MESSAGE_PAGE_SIZE, useConversationMessagePages } from "./use-conversation-message-pages";
import { useConversationScrollJump } from "./use-conversation-scroll-jump";
import { useConversationSignals } from "./use-conversation-signals";

/** What the host's activity components get: the turn's state and the paced lines of the activity ticker. */
export interface AgentChatActivityProps {
  /** A turn is in flight (derived from the messages and the run status). */
  active: boolean;
  /** The chat shows an error card. */
  failed: boolean;
  activityTicker: ActivityTicker;
  /** The agent works on a task: a turn is in flight or the ticker still shows its lines. */
  isAgentWorkingOnTask: boolean;
  /** The key of the agent space the chat is open on. */
  agentSpaceKey: number;
}

/** What the host's activity history under a message gets: the finished turn that message started, if any. */
export interface AgentChatActivityHistoryProps {
  turn: ActivityTickerTurn | null;
}

/** Where the model of the next send comes from — read at send time (an admin's pick can change between renders). */
export interface AgentChatSendModelSource {
  readModelForNextSend: () => string | undefined;
}

export interface AgentChatControllerProps {
  /** The state handle of this chat instance (created and released by the app). */
  chatStore: AgentsChatStore;
  /** The conversation client this chat reads and sends through. */
  client: AgentsConversationClient;
  /** The key of the agent space new conversations start in. */
  agentSpaceKey: number;
  /** When set, load + resume this existing conversation. Absent = empty chat, lazy-starts on first send. */
  conversationId?: number;
  onNewConversation?: (conversationId: number) => void;
  /** The conversation could not be loaded (missing, deleted, not readable): the host drops to the empty chat. */
  onConversationNotFound?: () => void;
  /** Switch the active conversation — the ConversationSearchResults widget's open-arrow target. */
  onOpenConversation?: (conversationId: number, messageId?: number) => void;
  /** Switch this chat to another conversation of the same agent space in place (a widget linking to related work). */
  onSwitchConversation?: (conversationId: number) => void;
  /** Text inserted into the composer once on mount — never sent automatically. */
  initialComposerPrompt?: string;
  /** A request to scroll a message into view and flash it; re-applied whenever its `nonce` changes. */
  scrollTarget?: ChatScrollTarget;
  /** Each finished turn's activity lines stay in the thread above its answer (rendered by `ActivityHistory`). */
  keepActivityHistoryAfterAnswer?: boolean;
  /** The host shows one conversation the owner may not leave: no starting ideas (the agent speaks first). */
  isPinnedToSingleConversation?: boolean;
  /** App extension of the widget context: the chat is the app's guided first-run setup. */
  isOnboardingSetupConversation?: boolean;
  /** The model of the next send; without it the conversation's own model drives. */
  sendModelSource?: AgentChatSendModelSource;
  /** Called after a send that carried attachments, so the host clears them from the composer. */
  onAttachmentsSent?: () => void;
  /** The empty state of a new chat: a synthetic first assistant message (client state only), as markdown. */
  startingIdeasMarkdown?: string;
  /** While true the chat follows the bottom; a rising edge jumps to the newest message (a voice chat). */
  followBottom?: boolean;
  /** The chat's scroll container (the host may own it, e.g. for a scroll-linked background). */
  chatScrollRef: RefObject<HTMLDivElement | null>;
  isCompact?: boolean;
  confineTopBlurBandToChatPane?: boolean;
  /** Class names of the two wrappers around the conversation view (the host's layout). */
  chatAreaClassName?: string;
  chatWrapClassName?: string;
  /** Slots of the conversation view. */
  progressStepperSlot?: ReactNode;
  inputActionsSlot?: ReactNode;
  inputTopSlot?: ReactNode;
  inputSendDisabled?: boolean;
  inputReplacementSlot?: ReactNode;
  inputBandSlot?: ReactNode;
  inputPlaceholder?: string;
  inputSendReplacementWhenEmpty?: ReactNode;
  inputGlowUnderlay?: ReactNode;
  isContentOverlayShown?: boolean;
  isQuestionPinSuspended?: boolean;
  /** The attachments that ride along on the next send. */
  outgoingAttachments?: ConversationAttachmentReference[];
  /** The agent's activity at the tail of the thread (the agent avatar slot). */
  ThinkingIndicator: ComponentType<AgentChatActivityProps>;
  /** A layer over the message column (a voice chat's focus mode); shown while `isContentOverlayShown`. */
  ContentOverlay?: ComponentType<AgentChatActivityProps>;
  /** The kept activity lines under the message that started a turn (with `keepActivityHistoryAfterAnswer`). */
  ActivityHistory?: ComponentType<AgentChatActivityHistoryProps>;
  /** Rendered inside the chat's widget provider (the host's canvas syncs). */
  children?: ReactNode;
}

/** Returns true when the messages contain an ERROR message whose signalSource is AI_BUDGET_REACHED. */
const hasBudgetReachedError = (messages: ChatMessage[] | null): boolean =>
  (messages ?? []).some((message) => message.messageType === "ERROR" && message.signalSource === "AI_BUDGET_REACHED");

/** Every port function rejects with `AgentsClientError`; anything else (a bug) reads as an `other` refusal. */
const toAgentsClientErrorOfPort = (failure: unknown): AgentsClientError =>
  isAgentsClientError(failure) ? failure : new AgentsClientError("other");

const selectMessages = (state: AgentsChatState) => state.messages;
const selectError = (state: AgentsChatState) => state.error;
const selectRateLimitRetryAfterSeconds = (state: AgentsChatState) => state.rateLimitRetryAfterSeconds;
const selectConversation = (state: AgentsChatState) => state.conversation;
const selectStartingNewConversation = (state: AgentsChatState) => state.startingNewConversation;
const selectCanvas = (state: AgentsChatState) => state.canvas;
const selectHasMoreOlder = (state: AgentsChatState) => state.hasMoreOlder;
const selectOpeningRequest = (state: AgentsChatState) => state.openingRequest;

/**
 * The agent chat: the controller over the conversation client port and the chat store — open, pages,
 * live updates, sends, signals, the error card and retry, the opening request, the deep-linked widget, the scroll jump
 * and the activity — rendering the conversation view with the host's slots. The drive is async: a reply never comes
 * in the start/post answer, it arrives through the live updates.
 */
export const AgentChatController = (props: AgentChatControllerProps) => {
  const {
    chatStore,
    client,
    agentSpaceKey,
    conversationId,
    onNewConversation,
    onConversationNotFound,
    onOpenConversation,
    onSwitchConversation,
    initialComposerPrompt,
    scrollTarget,
    keepActivityHistoryAfterAnswer = false,
    isPinnedToSingleConversation = false,
    isOnboardingSetupConversation = false,
    sendModelSource,
    onAttachmentsSent,
    startingIdeasMarkdown,
    followBottom = false,
    chatScrollRef,
    isCompact = false,
    confineTopBlurBandToChatPane = false,
    chatAreaClassName,
    chatWrapClassName,
    progressStepperSlot,
    inputActionsSlot,
    inputTopSlot,
    inputSendDisabled,
    inputReplacementSlot,
    inputBandSlot,
    inputPlaceholder,
    inputSendReplacementWhenEmpty,
    inputGlowUnderlay,
    isContentOverlayShown,
    isQuestionPinSuspended,
    outgoingAttachments,
    ThinkingIndicator,
    ContentOverlay,
    ActivityHistory,
    children,
  } = props;

  // The composer's handle: the initial prompt and a widget's `prefillComposer` put text into it without sending.
  const conversationMessageInputApiRef = useRef<ConversationMessageInputHandle>(null);
  useEffect(() => {
    if (!initialComposerPrompt) return;
    conversationMessageInputApiRef.current?.insertTextIntoInput(initialComposerPrompt);
    // Mount-only: a one-shot initial value, not a live prop to keep re-applying.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const messages = useAgentsChatSelector(chatStore, selectMessages);
  const error = useAgentsChatSelector(chatStore, selectError);
  const rateLimitRetryAfterSeconds = useAgentsChatSelector(chatStore, selectRateLimitRetryAfterSeconds);
  const conversation = useAgentsChatSelector(chatStore, selectConversation);
  const startingNewConversation = useAgentsChatSelector(chatStore, selectStartingNewConversation);
  const canvas = useAgentsChatSelector(chatStore, selectCanvas);
  const hasMoreOlder = useAgentsChatSelector(chatStore, selectHasMoreOlder);
  const openingRequest = useAgentsChatSelector(chatStore, selectOpeningRequest);
  const pendingStartUserMessage = openingRequest.userMessage;
  const pendingStartSignalText = openingRequest.startSignalText;
  const pendingStartEntryPointKey = openingRequest.entryPointKey;
  const pendingDeepLinkedWidgetName = openingRequest.deepLinkedWidgetName;

  // A terminal runState: the run is over, the server rejects further owner input (the closed bar replaces the input).
  const isConversationRunEnded = conversation?.runState === "COMPLETED" || conversation?.runState === "CANCELLED";

  // The message reads (newest page, changed tail, older page, metadata) over the client, written into the store.
  const messagePages = useConversationMessagePages(chatStore, client, CONVERSATION_MESSAGE_PAGE_SIZE);
  const { readConversationMetadata, fetchOpeningPage, loadOlder, loadingOlder } = messagePages;

  // Load an existing conversation: the metadata (no messages, non-destructive) apart from the newest page (merged,
  // never clears — no skeleton flash). A refused metadata read drops the host to the empty chat.
  useEffect(() => {
    if (!conversationId) {
      // Empty chat: reset so a stale conversation can't linger; the first owner message lazy-starts a new one.
      chatStore.dispatch({ type: "reset" });
      return;
    }
    if (String(conversation?.id) === String(conversationId)) return;
    chatStore.dispatch({ type: "reset" });
    void readConversationMetadata(conversationId).then((wasRead) => {
      if (!wasRead) onConversationNotFound?.();
    });
    fetchOpeningPage(conversationId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversationId]);

  // Reset the chat state on unmount so reopening the chat starts clean.
  useEffect(() => {
    return () => {
      chatStore.dispatch({ type: "reset" });
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const liveConversationId = conversation?.id ?? conversationId;

  // The deep-linked widget hand-over (the opening request's `deepLinkedWidgetName`): mounted ONCE per hand-over under
  // a fresh mount key, released once the canvas it declares (`opensCanvases`) is open — never just "some canvas" — or
  // after 15 s for a widget that opens none. A cold chat that remounts meanwhile keeps the request.
  const [deepLinkedWidget, setDeepLinkedWidget] = useState<{ name: string; mountKey: number } | undefined>(undefined);
  const mountedHandOverRef = useRef<string | null>(null);
  useEffect(() => {
    if (!pendingDeepLinkedWidgetName) {
      mountedHandOverRef.current = null;
      return;
    }
    if (mountedHandOverRef.current === pendingDeepLinkedWidgetName) return;
    mountedHandOverRef.current = pendingDeepLinkedWidgetName;
    setDeepLinkedWidget((previous) => ({ name: pendingDeepLinkedWidgetName, mountKey: (previous?.mountKey ?? 0) + 1 }));
  }, [pendingDeepLinkedWidgetName]);
  const handedOverOpensCanvas =
    canvas !== null &&
    (resolveDeepLinkableWidget(pendingDeepLinkedWidgetName)?.opensCanvases?.includes(canvas.canvasId) ?? false);
  useEffect(() => {
    if (!pendingDeepLinkedWidgetName) return;
    const releaseHandOver = () =>
      chatStore.dispatch({ type: "openingRequestSet", openingRequest: { deepLinkedWidgetName: null } });
    if (handedOverOpensCanvas) {
      releaseHandOver();
      return;
    }
    const release = window.setTimeout(releaseHandOver, 15000);
    return () => window.clearTimeout(release);
  }, [pendingDeepLinkedWidgetName, handedOverOpensCanvas, chatStore]);

  const hasMoreOlderRef = useRef(hasMoreOlder);
  hasMoreOlderRef.current = hasMoreOlder;
  const messagesRef = useRef(messages);
  messagesRef.current = messages;
  // Scroll-up pagination: reads the store, so its identity changes only with the conversation (the sentinel's
  // IntersectionObserver is not torn down on every message change).
  const loadOlderOfLiveConversation = useCallback(() => loadOlder(liveConversationId), [loadOlder, liveConversationId]);

  // The title lands asynchronously after the first turn: re-read the metadata only (the messages stay as they are).
  const refetchConversationSummary = useCallback(() => {
    if (!liveConversationId) return;
    void readConversationMetadata(liveConversationId);
  }, [readConversationMetadata, liveConversationId]);

  // Live updates: the package rules over the client's subscription; the binding owns the transport.
  const { runStatus, liveReadCount, readChangedTailNow } = useConversationLiveUpdates({
    client,
    chatStore,
    messagePages,
    conversationId: liveConversationId,
  });

  // What the agent is doing right now, one generated line per step, paced by the ticker.
  const {
    elements: activityElements,
    running: activityRunning,
    turnMessageId: activityTurnMessageId,
  } = useConversationActivity({
    conversationId: liveConversationId ?? 0,
    runStatus,
    // Every live read also asks for the activity lines; the hook throttles to one request per 3 s.
    signalVersion: liveReadCount,
    enabled: Boolean(liveConversationId),
  });
  const activityTicker = useActivityTicker({
    elements: activityElements,
    running: activityRunning,
    turnMessageId: activityTurnMessageId,
    failed: !!error,
    keepActivityHistoryAfterAnswer,
  });

  // The run status → the error card only (aiThinking follows the derived activity below). messages is a dependency:
  // the ERROR message with signalSource=AI_BUDGET_REACHED lands slightly after the status flips to "failed".
  useEffect(() => {
    if (runStatus === "failed") {
      chatStore.dispatch({
        type: "errorSet",
        error: hasBudgetReachedError(messages) ? ConversationErrorType.AI_BUDGET : ConversationErrorType.AGENT_ERROR,
      });
    } else if (runStatus === "running") {
      chatStore.dispatch({ type: "errorSet", error: null });
    }
  }, [runStatus, chatStore, messages]);

  // Retry of a FAILED run: re-drives the stored, unanswered owner message; accepted → the card goes.
  const handleRetryFailedRun = useCallback(async () => {
    const retryConversationId = conversationId ?? conversation?.id;
    if (!retryConversationId) {
      return;
    }
    await client.retryConversation(retryConversationId);
    chatStore.dispatch({ type: "errorSet", error: null });
  }, [conversationId, conversation?.id, client, chatStore]);

  // Bumped whenever the chat must jump to the newest content: a widget click that injects a signal, the closed refusal.
  const [scrollToBottomNonce, setScrollToBottomNonce] = useState(0);

  // While the host asks to follow the bottom (a voice chat), a question pinned by an earlier send must not keep the
  // new lines below the fold.
  useEffect(() => {
    if (followBottom) setScrollToBottomNonce((nonce) => nonce + 1);
  }, [followBottom]);

  /** The closed refusal on the error card, without any request — and the jump, so the card is in view. */
  const rejectAsConversationClosed = useCallback(() => {
    chatStore.dispatch({ type: "errorSet", error: ConversationErrorType.CONVERSATION_CLOSED });
    setScrollToBottomNonce((nonce) => nonce + 1);
  }, [chatStore]);

  const handleSendMessage = useCallback<SendMessageHandler>(
    (message, { startSignalText, attachments, entryPointKey }) => {
      // A closed conversation takes no further input — the typed input and every widget send.
      if (isConversationRunEnded) {
        rejectAsConversationClosed();
        return false;
      }
      // The canvas model's SEND boundary: a turn canvas ends, a pinned one stays.
      chatStore.dispatch({ type: "canvasResetForNewTurn" });
      const model = sendModelSource?.readModelForNextSend();
      // The optimistic bubble shows the text the server stores (attachments as the binding writes them).
      const sentMessageText = client.composeSentMessageText
        ? client.composeSentMessageText(message, attachments)
        : message;
      // Only a send that carried attachments clears them: a widget send and the cold-start greeting carry none and
      // must never wipe what the owner is still composing.
      const reportSentAttachments = () => {
        if (attachments?.length) onAttachmentsSent?.();
      };
      if (conversation?.id) {
        const postConversationId = conversation.id;
        chatStore.dispatch({ type: "messagePostRequested", message: { message: sentMessageText, role: "USER" } });
        client
          .postMessage(postConversationId, { text: message, model, attachments })
          .then((posted) =>
            chatStore.dispatch({ type: "messagePostReceived", conversationId: postConversationId, posted }),
          )
          .catch((failure: unknown) =>
            chatStore.dispatch({ type: "messagePostFailed", error: toAgentsClientErrorOfPort(failure) }),
          );
        reportSentAttachments();
        return true;
      }
      // No conversation yet — lazy start; `startSignalText` rides along as the invisible start context.
      if (startingNewConversation) return false;
      // The store hands out the start's token (it outlives a remount): its answer applies only while it is pending.
      chatStore.dispatch({ type: "startRequested", message: { message: sentMessageText, role: "USER" } });
      const startToken = chatStore.getState().pendingStartToken as number;
      client
        .startConversation({ agentSpaceKey, text: message, startSignalText, entryPointKey, model, attachments })
        .then((started) => {
          const isAnswerOfPendingStart = chatStore.getState().pendingStartToken === startToken;
          chatStore.dispatch({ type: "startReceived", startToken, started });
          if (!isAnswerOfPendingStart) return;
          // A start that reopened its entry point's recent conversation brings no messages: read its newest page.
          if (started.reopenedExisting) {
            fetchOpeningPage(started.conversation.id);
          }
          onNewConversation?.(started.conversation.id);
        })
        .catch((failure: unknown) => {
          chatStore.dispatch({ type: "startFailed", error: toAgentsClientErrorOfPort(failure) });
        });
      reportSentAttachments();
      return true;
    },
    [
      conversation,
      startingNewConversation,
      chatStore,
      client,
      agentSpaceKey,
      sendModelSource,
      onAttachmentsSent,
      isConversationRunEnded,
      rejectAsConversationClosed,
      fetchOpeningPage,
      onNewConversation,
    ],
  );

  // The opening request's message or start signal (an entry point outside the chat): posted as the first owner
  // message of a cold chat — keyed on the request, not on mount (the chat may already be open), never into an existing
  // conversation and never while a start is in flight. The send is read through a ref so it can't re-fire.
  const handleSendMessageRef = useRef(handleSendMessage);
  handleSendMessageRef.current = handleSendMessage;
  const sentStartRequestRef = useRef<PendingStartRequest | null>(null);
  const loadedConversationId = conversation?.id;
  useEffect(() => {
    const pendingStartRequest = pickPendingStartRequest(pendingStartUserMessage, pendingStartSignalText);
    if (!pendingStartRequest) {
      // Nothing pending — re-arm, so the SAME entry point can be clicked again.
      sentStartRequestRef.current = null;
      return;
    }
    const isReadyToSend = isChatReadyToSendPendingStartRequest({
      pendingStartRequest,
      activeConversationId: conversationId,
      loadedConversationId,
      startingNewConversation,
      alreadySentStartRequest: sentStartRequestRef.current,
    });
    if (!isReadyToSend) {
      return;
    }
    sentStartRequestRef.current = pendingStartRequest;
    // All cleared: a losing signal left behind would make the host drop the conversation this start just opened.
    chatStore.dispatch({
      type: "openingRequestSet",
      openingRequest: { userMessage: null, startSignalText: null, entryPointKey: null },
    });
    const entryPointKey = pendingStartEntryPointKey ?? undefined;
    if (pendingStartRequest.kind === "signal") {
      handleSendMessageRef.current("", { startSignalText: pendingStartRequest.text, entryPointKey });
    } else {
      handleSendMessageRef.current(pendingStartRequest.text, { entryPointKey });
    }
  }, [
    pendingStartUserMessage,
    pendingStartSignalText,
    pendingStartEntryPointKey,
    conversationId,
    loadedConversationId,
    startingNewConversation,
    chatStore,
  ]);

  // The signals besides the owner's messages: a widget's signal, a parked context-only signal, the corrective signal
  // for a dropped widget block, the widget render report (one collector per conversation).
  const { handleInjectSignal, renderReportCollector } = useConversationSignals({
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
  });

  // Scroll-to-message and keeping the activity ticker in view; owns the bottom-follow refs of the view body.
  const { followBottomRef, scrollJumpActiveRef } = useConversationScrollJump({
    scrollTarget,
    conversationId,
    conversation,
    messages,
    messagesRef,
    hasMoreOlderRef,
    loadOlder: loadOlderOfLiveConversation,
    chatScrollRef,
    activityTicker,
  });

  const widgetCallbacks = useMemo(
    () => ({
      // Message-only for widgets; resolves true only once the send was accepted (a widget locks on it).
      sendMessageToChat: (message: string) => Promise.resolve(handleSendMessage(message, {}) === true),
      injectSignal: handleInjectSignal,
      prefillComposer: (text: string) => conversationMessageInputApiRef.current?.insertTextIntoInput(text),
      chatAgentSpaceKey: agentSpaceKey,
      conversationId: liveConversationId,
      onOpenConversation,
      onSwitchConversation,
      isOnboardingSetupConversation,
    }),
    [
      handleSendMessage,
      handleInjectSignal,
      agentSpaceKey,
      liveConversationId,
      onOpenConversation,
      onSwitchConversation,
      isOnboardingSetupConversation,
    ],
  );

  // "What is the agent doing right now" — from the live messages and the run status.
  const agentActivity = useMemo(() => deriveAgentActivity(messages, runStatus), [messages, runStatus]);

  // The store's aiThinking (the launcher reads it) follows the activity the open chat shows.
  useEffect(() => {
    chatStore.dispatch({ type: "aiThinkingSet", aiThinking: agentActivity.active });
  }, [agentActivity.active, chatStore]);
  const isTurnRunning = agentActivity.active || runStatus === "running";
  useEffect(() => (isTurnRunning ? reportRunningTurn() : undefined), [isTurnRunning]);

  // Changes ONLY when a new message arrives (the merge re-allocates the list with identical content) — the clock of
  // the safety poll below.
  const latestMessageSignature = useMemo(() => {
    const list = messages ?? [];
    const last = list[list.length - 1];
    return `${list.length}:${last?.id ?? ""}`;
  }, [messages]);

  // The safety poll: one read after a full minute without a new message while a turn is in flight — the floor for a
  // dead live path, never the live path itself.
  useEffect(() => {
    if (!liveConversationId || !agentActivity.active) return;
    const intervalId = setInterval(readChangedTailNow, 60_000);
    return () => clearInterval(intervalId);
  }, [liveConversationId, agentActivity.active, readChangedTailNow, latestMessageSignature]);

  // A conversation of a scheduled run: its first owner message is the task's seed, shown collapsed — only when the
  // history is loaded down to its start.
  const isScheduleRunConversation = conversation?.initiatorType === "SCHEDULE";
  const taskInstructionMessageId = useMemo(() => {
    if (!isScheduleRunConversation || hasMoreOlder || !messages?.length) return undefined;
    return messages.find((message) => message.role === "USER")?.id ?? undefined;
  }, [isScheduleRunConversation, hasMoreOlder, messages]);

  // With keepActivityHistoryAfterAnswer, each finished turn's lines stand under the message that started the turn.
  const activityHistoryByTurnMessageId = useMemo(() => {
    const historyTurnsByTurnMessageId = new Map<number, ActivityTickerTurn>();
    activityTicker.historyTurns.forEach((historyTurn) => {
      if (historyTurn.turnMessageId !== null) historyTurnsByTurnMessageId.set(historyTurn.turnMessageId, historyTurn);
    });
    return historyTurnsByTurnMessageId;
  }, [activityTicker.historyTurns]);

  // The starting ideas: not on a host pinned to one conversation, not beside an open canvas.
  const showsStartingIdeas =
    startingIdeasMarkdown !== undefined &&
    !conversationId &&
    !messages?.length &&
    !isPinnedToSingleConversation &&
    canvas === null;
  const chatMessages = useMemo(() => {
    if (!showsStartingIdeas) return messages;
    const startingIdeasMessage: ChatMessage = { id: -1, role: "ASSISTANT", message: startingIdeasMarkdown, fresh: true };
    return [startingIdeasMessage];
  }, [showsStartingIdeas, startingIdeasMarkdown, messages]);

  const renderActivityHistoryAfterMessage = useCallback(
    (message: ChatMessage) =>
      ActivityHistory ? (
        <ActivityHistory
          turn={typeof message.id === "number" ? (activityHistoryByTurnMessageId.get(message.id) ?? null) : null}
        />
      ) : null,
    [ActivityHistory, activityHistoryByTurnMessageId],
  );

  const activityProps: AgentChatActivityProps = {
    active: agentActivity.active,
    failed: !!error,
    activityTicker,
    isAgentWorkingOnTask: agentActivity.active || activityTicker.phase === "live",
    agentSpaceKey,
  };

  return (
    <WidgetRenderReportCollectorContext.Provider value={renderReportCollector}>
      <ConversationWidgetProvider callbacks={widgetCallbacks} chatStore={chatStore}>
        {/* The tool a page handed over: renders nothing in the chat itself — such a widget lives in the canvas. */}
        <ConversationDeepLinkedWidget
          key={deepLinkedWidget?.mountKey ?? 0}
          widgetName={deepLinkedWidget?.name}
          agentSpaceKey={agentSpaceKey}
        />
        {/* The canvas that was open in this conversation comes back once ITS messages are in. */}
        <CanvasRestoreOnMount conversationId={liveConversationId} messages={messages} />
        {children}
        <div className={chatAreaClassName}>
          <div className={chatWrapClassName}>
            <ConversationView
              enableCanvasToggle
              boxCanvasBelowTopBar
              confineTopBlurBandToChatPane={confineTopBlurBandToChatPane}
              progressStepperSlot={progressStepperSlot}
              enablePinQuestionAndRevealAnswer
              isQuestionPinSuspended={isQuestionPinSuspended}
              scrollRef={chatScrollRef}
              scrollToBottomNonce={scrollToBottomNonce}
              followBottomRef={followBottomRef}
              scrollJumpActiveRef={scrollJumpActiveRef}
              // The host paints the background at its own root; this pane keeps the transparent custom-background mode.
              backgroundPaintedByHost
              currentCategory={undefined}
              aiPromptExtension={undefined}
              suggestions={undefined}
              messages={chatMessages}
              aiThinking={agentActivity.active}
              // The input never locks while the agent works: a message posted mid-turn is answered in the next turn.
              allowSendWhileAiThinking
              thinkingIndicator={<ThinkingIndicator {...activityProps} />}
              // Only while the history is kept: otherwise every row would carry an empty history slot.
              renderAfterMessage={keepActivityHistoryAfterAnswer ? renderActivityHistoryAfterMessage : undefined}
              hideAssistantMeta
              userMessageTheme="dark"
              error={error}
              rateLimitRetryAfterSeconds={rateLimitRetryAfterSeconds}
              onRetryFailedRun={handleRetryFailedRun}
              isDebugMode={false}
              categoriesLoaded={true}
              conversationId={conversationId ?? conversation?.id ?? undefined}
              startingNewConversation={startingNewConversation}
              showMessagesWithoutConversation
              hasMoreOlder={hasMoreOlder}
              loadingOlder={loadingOlder}
              onLoadOlder={loadOlderOfLiveConversation}
              autoScroll={!!conversationId || (messages?.length ?? 0) > 0}
              isCompact={isCompact}
              inputActionsSlot={inputActionsSlot}
              inputTopSlot={inputTopSlot}
              inputSendDisabled={inputSendDisabled}
              inputReplacementSlot={inputReplacementSlot}
              inputBandSlot={inputBandSlot}
              inputPlaceholder={inputPlaceholder}
              inputSendReplacementWhenEmpty={inputSendReplacementWhenEmpty}
              inputGlowUnderlay={inputGlowUnderlay}
              contentOverlaySlot={ContentOverlay ? <ContentOverlay {...activityProps} /> : undefined}
              isContentOverlayShown={isContentOverlayShown}
              outgoingAttachmentImages={outgoingAttachments}
              taskInstructionMessageId={taskInstructionMessageId}
              isConversationClosed={isConversationRunEnded}
              conversationMessageInputApiRef={conversationMessageInputApiRef}
              onSuggestionClick={() => {}}
              onSendMessage={handleSendMessage}
            />
          </div>
        </div>
      </ConversationWidgetProvider>
    </WidgetRenderReportCollectorContext.Provider>
  );
};
