import { Button, SplitView } from "../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity, ChatAssistantOpening } from "./chat-assistant-identity";
import { classNames } from "../provider/class-names";
import React, { CSSProperties, useRef, useState } from "react";
import { useCanvasViable } from "../widgets/CanvasWidgetCard/use-canvas-viable";
import {
  CANVAS_BOXED_INSET_PX,
  CANVAS_MIN_WIDTH_PX,
  CHAT_COLUMN_MIN_WIDTH_PX,
  DEFAULT_CANVAS_WIDTH_PERCENT,
  type CanvasId,
  canvasBehaviourOf,
  canvasWidthPercentOf,
} from "../renderer/canvas/canvas-behaviours";
import { ConversationViewBody } from "./ConversationViewBody/ConversationViewBody";
import { ConversationErrorType } from "./store/conversation-error-type";
import type { ChatMessage } from "./chat-message";
import { ConversationMessageUserTheme } from "./ConversationMessage/ConversationMessageUser/ConversationMessageUser";
import { ConversationMessageInputHandle } from "./ConversationMessageInput/ConversationMessageInput";
import type { ConversationAttachmentReference } from "../contract/conversation";
import { SendMessageHandler } from "./conversation-send-message";
import { CanvasPane } from "./CanvasPane/CanvasPane";
import { CanvasResizeHandle } from "./CanvasResizeHandle/CanvasResizeHandle";
import { useCanvasShellTransitions } from "./use-canvas-shell-transitions";
import { useConversationWidgetContext } from "../renderer/ConversationWidgetContext";
import classes from "./Conversation.module.scss";

export interface ConversationViewProps {
  currentCategory?: ChatAssistantCategory; // Type from categories
  aiPromptExtension?: ChatAssistantIdentity; // Type from agents
  suggestions?: ChatAssistantOpening[]; // Type from conversationOpenings
  messages: ChatMessage[] | null; // Type from messages
  aiThinking: boolean;
  error: ConversationErrorType | null;
  /** Retry-After seconds of a 429 quota rejection — forwarded to the error card for the "try again in …" wording. */
  rateLimitRetryAfterSeconds?: number | null;
  /**
   * Host-bound retry of a FAILED run: re-drives the persisted, unanswered customer message
   * server-side. Each host binds it to ITS chat slice; forwarded through the body to the message list / error card.
   */
  onRetryFailedRun?: () => Promise<void>;
  /**
   * True when the host (the agent) is minimized to the compact side-panel; forwarded to ConversationViewBody.
   */
  isCompact?: boolean;
  isDebugMode: boolean;
  categoriesLoaded: boolean;
  conversationId?: number;
  startingNewConversation?: boolean;
  /**
   * Show the message list even when no conversation exists yet. Used by a host,
   * whose fresh (not-yet-created) chat renders its empty message list.
   */
  showMessagesWithoutConversation?: boolean;
  /**
   * Follow the conversation bottom (scroll-on-mount + resize follow). Default true.
   * A host disables it for a fresh chat that has no messages yet.
   */
  autoScroll?: boolean;
  /**
   * Custom node mounted at the tail of the message list instead of the default
   * (thinking-only) ConversationMessageThinking. Rendered permanently — used by
   * A host to show the animated agent face, which idles between turns.
   */
  thinkingIndicator?: React.ReactNode;
  /**
   * Hide the assistant header (icon + name + "Assistant") and the copy button
   * on assistant messages. Used by a host, which renders bare messages.
   */
  hideAssistantMeta?: boolean;
  /**
   * Theme for user-message bubbles, passed through to ConversationViewBody →
   * ConversationMessageList. A host forces "dark"; omitted elsewhere.
   */
  userMessageTheme?: ConversationMessageUserTheme;
  /**
   * Custom background slot passed through to ConversationViewBody. When provided, the
   * default chat gradient and AI-Extensions illustrations are suppressed.
   */
  background?: React.ReactNode;
  /**
   * Opt-in: the host paints the chat's background itself, OUTSIDE this component — passed through to ConversationViewBody,
   * which keeps the same `customBg` visual mode (transparent pane) `background` would give it, minus
   * a background node of its own to render (see ConversationViewBody's `backgroundPaintedByHost`).
   */
  backgroundPaintedByHost?: boolean;
  /**
   * Optional ref forwarded to ConversationViewBody's scrollable content wrapper.
   * Callers use this to drive scroll-linked effects on top of the chat — e.g.
   * `useShaderScrollMorph` for the animated background.
   */
  scrollRef?: React.Ref<HTMLDivElement>;
  /** Bump to force a scroll to the newest message — forwarded to ConversationViewBody (see its `scrollToBottomNonce`). */
  scrollToBottomNonce?: number;
  /**
   * Optional external bottom-follow flag, forwarded to ConversationViewBody (see its `followBottomRef`). A host sets it `false`
   * for a programmatic jump away from the bottom (e.g. the search-result jump) so the follow effects stop yanking the
   * view back. Left undefined, ConversationViewBody uses its own internal flag.
   */
  followBottomRef?: React.MutableRefObject<boolean>;
  /**
   * Optional "a programmatic jump is in flight" gate, forwarded to ConversationViewBody (see its `scrollJumpActiveRef`). While
   * true, ConversationViewBody neither re-enables bottom-follow on scroll nor auto-scrolls to the bottom on resize, so the
   * host's jump isn't fought by the near-bottom onScroll handler or the resize-follow. Left undefined → no gating.
   */
  scrollJumpActiveRef?: React.MutableRefObject<boolean>;
  /**
   * Render the chat body alone, without the two-pane ModalSplitView. The split view's
   * second pane is sized for a full-width modal, so in a narrow side panel (e.g. an
   * app's editor drawer) it overlays whatever sits beside the panel — and those hosts
   * don't use widget content-drawers anyway.
   */
  disableSplitView?: boolean;
  /**
   * Opt-in: show the round divider FAB that toggles the canvas (open → close, closed → re-open the last canvas).
   * Off by default — ConversationView is shared (generic AI-extensions chat, a host, agent); only hosts that want the
   * divider toggle (agent) enable it.
   */
  enableCanvasToggle?: boolean;
  /**
   * Opt-in (agent): render the side-split canvas as a rounded surface card (border-radius + surface background)
   * so it sits neatly alongside the left side of the host's floating top bar, instead of a flush full-height pane.
   * Only the card surface is gated here and only while the canvas is a side-split (viewport ≥ 850px); the small
   * inset that keeps the canvas clear of the pane edge applies to every canvas (see CanvasPane `.root`),
   * including the narrow full-screen overlay below 850px (where the host hides its top bar, AW-57).
   */
  boxCanvasBelowTopBar?: boolean;
  /**
   * Opt-in (agent host only): confine the customBg top blur band to the chat pane (position: sticky, sized to
   * the pane) instead of the whole viewport (position: fixed, the default for a custom `background` in full-screen
   * mode). Turn on only while a sibling side panel is docked beside the chat (sideCard mode) — otherwise the band
   * would stay full-viewport-width and bleed over the panel instead of compressing together with the chat. Forwarded
   * straight through to ConversationViewBody, which owns the actual CSS (see its `.confineTopBlurBandToChatPane`).
   */
  confineTopBlurBandToChatPane?: boolean;
  /**
   * The setup progress stepper (today's only consumer: the agent chat), forwarded straight through to
   * ConversationViewBody, which mounts it as a direct child of its positioned pane root — the node brings its own
   * positioning. It lands INSIDE this view's split, so an open canvas contracts it along with the chat; that pane
   * boundary is why the host cannot place the strip itself.
   */
  progressStepperSlot?: React.ReactNode;
  /**
   * Scroll-up older-message pagination (opt-in — forwarded to ConversationViewBody). When `onLoadOlder` is provided, a top
   * sentinel prefetches the next older page and the viewport is anchored across the prepend. Default off.
   */
  hasMoreOlder?: boolean;
  loadingOlder?: boolean;
  onLoadOlder?: () => void;
  /**
   * Extra action controls rendered inside the chat input's action row, left of the send button —
   * spread through to ConversationViewBody → ConversationMessageInput (e.g. the agent model picker pill).
   */
  inputActionsSlot?: React.ReactNode;
  /**
   * Action controls pinned to the LEFT edge of the chat input's action row (`inputActionsSlot`
   * stays right) — spread through to ConversationViewBody → ConversationMessageInput (e.g. the agent "+" attachments menu).
   */
  inputActionsSlotStart?: React.ReactNode;
  /**
   * Host-owned content rendered inside the chat input above its editable text area — attachment
   * preview cards, validation error boxes. Spread through to ConversationViewBody → ConversationMessageInput's `topSlot`.
   */
  inputTopSlot?: React.ReactNode;
  /**
   * Hard send gate for the chat input: blocks the send button AND Enter while true, but keeps the
   * text editable. The host sets it e.g. while attachment uploads run.
   */
  inputSendDisabled?: boolean;
  /**
   * Host-owned content rendered IN PLACE of the chat input — the closed-conversation bar of a completed
   * setup conversation (no further input possible). When set, ConversationViewBody renders it instead of ConversationMessageInput.
   */
  inputReplacementSlot?: React.ReactNode;
  /**
   * Host-owned band right above the chat input, the input overlapping its lower edge — e.g. the running voice chat
   * with its controls. Not rendered while `inputReplacementSlot` replaces the input.
   */
  inputBandSlot?: React.ReactNode;
  /** The chat input's placeholder; the input's own default otherwise. */
  inputPlaceholder?: string;
  /** Forwarded to ConversationMessageInput's `sendReplacementWhenEmpty`. */
  inputSendReplacementWhenEmpty?: React.ReactNode;
  /** Forwarded to ConversationMessageInput's `glowUnderlay`. */
  inputGlowUnderlay?: React.ReactNode;
  /**
   * Host-owned layer over the message column, the same size — e.g. the voice chat's focus mode. The message column
   * stays mounted under it (scroll position and widget state kept); `isContentOverlayShown` switches between them.
   */
  contentOverlaySlot?: React.ReactNode;
  isContentOverlayShown?: boolean;
  /**
   * Uploaded media-library images that ride along in `onSendMessage`'s options object (`attachments`) on the next
   * send. Owned and cleared by the host; ConversationView/ConversationMessageInput only forward them.
   */
  outgoingAttachmentImages?: ConversationAttachmentReference[];
  /**
   * Imperative handle on the chat input (insertTextIntoInput) so host-owned controls — e.g. the
   * "+" menu's "Generate image" entry — can put text into it.
   */
  conversationMessageInputApiRef?: React.Ref<ConversationMessageInputHandle>;
  /**
   * Keep the chat input editable and sendable while `aiThinking` is true (default false — the input locks for the
   * whole turn); only the first post of a new conversation still locks it, while no conversation id exists yet. Opt
   * in ONLY for a host whose post call stores the message and returns at once, and whose agent checks for unanswered
   * messages when its turn ends, so a message sent mid-turn is answered in the next turn. A host whose post call
   * generates the reply synchronously within the request has no such guard and must leave it off.
   */
  allowSendWhileAiThinking?: boolean;
  /**
   * Opt-in long-answer reading flow (AW-107), default false — spread through to ConversationViewBody. When enabled:
   * (1) on send, the question is pinned to the top with room reserved below it; (2) a scroll-to-latest-message
   * button floats above the input while the bottom is out of view; (3) a fresh static reply's markdown blocks
   * fade in staggered. AI Extensions stays off; the agent chat opts in.
   */
  enablePinQuestionAndRevealAnswer?: boolean;
  /** Forwarded to ConversationViewBody's `isQuestionPinSuspended`. */
  isQuestionPinSuspended?: boolean;
  /**
   * Id of the ONE user message ConversationMessageList renders as a collapsed task-instruction disclosure instead of an
   * owner bubble — the machine-composed seed instruction of a SCHEDULE-spawned run (UX overhaul W4 item 19).
   * Forwarded through ConversationViewBody. Undefined (every host except the agent chat) changes nothing.
   */
  taskInstructionMessageId?: number;
  /**
   * Host content rendered right after one message of the thread — the agent chat's activity card under the message that
   * started each turn. Forwarded through ConversationViewBody to ConversationMessageList. Undefined changes nothing.
   */
  renderAfterMessage?: (message: ChatMessage) => React.ReactNode;
  /** Forwarded through ConversationViewBody to ConversationMessageList: a closed conversation renders no widget controls. */
  isConversationClosed?: boolean;
  onSuggestionClick: (text: string) => void;
  onSendMessage: SendMessageHandler;
}

export const ConversationView = (props: ConversationViewProps) => {
  const { enableCanvasToggle, boxCanvasBelowTopBar, onSendMessage, conversationId, messages } = props;
  const { canvas, lastClosedCanvas, closeCanvas, reopenLastClosedCanvas, resetCanvasForNewTurn } =
    useConversationWidgetContext();
  const canvasViable = useCanvasViable();
  // The canvas is a pointer in the chat's slice; its geometry comes from its behaviour (`canvas-behaviours.ts`).
  const canvasBehaviour = canvas ? (canvasBehaviourOf(canvas.canvasId) ?? null) : null;
  const isCanvasOpen = canvas !== null;
  // The conversation switch clears the canvas; the wide → narrow crossing closes a `narrowViewport: "none"` canvas.
  useCanvasShellTransitions({ conversationId, canvasViable });

  // A new user message starts a new turn — the previous turn's canvas is now stale, so tear it down AND drop the
  // divider FAB (the reset clears `canReopenDrawer`). Runs before the reply arrives, so a fresh canvas opened by the
  // new reply is unaffected. A PINNED canvas (a work surface for the whole conversation, not one
  // turn's result) survives — that is the only difference to `resetCanvas`.
  const handleSendMessage: SendMessageHandler = (message, options) => {
    resetCanvasForNewTurn();
    onSendMessage(message, options);
  };

  // The canvas pane starts at the width its behaviour declares; the customer can pull its left edge (`CanvasResizeHandle`).
  // The split reads the width from `--agents-canvas-width-set` (a percentage, or the clamped pull in px), so a drag moves the edge without a
  // render. The width of the last shown canvas is kept while the pane slides out. The divider toggle + slide derive from it.
  const lastCanvasWidthPercentRef = useRef(DEFAULT_CANVAS_WIDTH_PERCENT);
  if (canvasBehaviour) lastCanvasWidthPercentRef.current = canvasWidthPercentOf(canvasBehaviour);
  const canvasWidthPercent = lastCanvasWidthPercentRef.current;
  const boxCanvas = !!boxCanvasBelowTopBar && canvasViable;
  const canvasInset = boxCanvas ? CANVAS_BOXED_INSET_PX : 0;
  const splitViewRootRef = useRef<HTMLDivElement>(null);
  // The pull belongs to the canvas it was made on: another canvas starts at its own default split.
  const lastCanvasIdRef = useRef<CanvasId | null>(null);
  if (canvas) lastCanvasIdRef.current = canvas.canvasId;
  const [pulledCanvas, setPulledCanvas] = useState<{ canvasId: CanvasId; width: number } | null>(null);
  const pulledCanvasWidth = pulledCanvas?.canvasId === lastCanvasIdRef.current ? pulledCanvas.width : null;
  const setPulledCanvasWidth = (width: number | null) =>
    setPulledCanvas(width !== null && lastCanvasIdRef.current ? { canvasId: lastCanvasIdRef.current, width } : null);
  const canvasResizable = canvasBehaviour?.layout === "split" && canvasViable && !props.isCompact;
  const splitViewRootStyle = {
    "--agents-canvas-width-set":
      pulledCanvasWidth === null
        ? `${canvasWidthPercent}%`
        : `clamp(${CANVAS_MIN_WIDTH_PX + canvasInset * 2}px, ${pulledCanvasWidth}px, calc(100% - ${CHAT_COLUMN_MIN_WIDTH_PX}px))`,
    "--agents-canvas-inset": `${canvasInset}px`,
  } as CSSProperties;

  // The canvas toggle button on the divider: closes the open canvas (the customer's own close), re-opens the last
  // closed one.
  const toggleCanvas = () => {
    if (canvas) closeCanvas(true);
    else reopenLastClosedCanvas();
  };
  // On a narrow viewport the divider (and its toggle) does not exist: the canvas is a full-screen overlay. The canvases
  // whose widgets render nothing inline would have no way back after a close, so the shell offers its own re-open
  // control over the chat, fed by `lastClosedCanvas`, independent of transcript windowing.
  const showsNarrowReopenControl = !!enableCanvasToggle && !canvasViable && !isCanvasOpen && lastClosedCanvas !== null;

  if (props.disableSplitView) {
    return <ConversationViewBody {...props} />;
  }

  return (
    <div ref={splitViewRootRef} className={classes.splitViewRoot} style={splitViewRootStyle}>
      {showsNarrowReopenControl ? (
        <div className={classes.canvasReopenControl}>
          <Button
            variant="muted"
            icon="maximize"
            iconPosition={"left"}
            rounded
            blurred
            size="small"
            onClick={reopenLastClosedCanvas}
          >
            Re-open canvas
          </Button>
        </div>
      ) : null}
      <SplitView
        isSideOpen={isCanvasOpen}
        coversBelowWidth={850}
        // The canvas toggle button on the divider: closes the open canvas, or re-opens the last closed one. Shown only
        // while a canvas is open or one was closed — otherwise there's nothing to toggle.
        sideToggle={
          enableCanvasToggle && !canvasResizable && (isCanvasOpen || lastClosedCanvas !== null)
            ? {
                onToggle: toggleCanvas,
                // Centered vertically on the divider.
                verticalPositionPercent: 50,
              }
            : undefined
        }
        main={{
          width: "100%",
          narrowedWidth: "calc(100% - var(--agents-canvas-width))",
          // The pane's default inline background (var(--agents-neutral-000)) would occlude a host-painted background layer.
          background: props.backgroundPaintedByHost ? "transparent" : undefined,
          content: <ConversationViewBody {...props} onSendMessage={handleSendMessage} />,
        }}
        side={{
          width: "var(--agents-canvas-width)",
          narrowedWidth: "var(--agents-canvas-width)",
          content: (
            // Boxed only while side-split: below 850px the canvas is a full-screen overlay and stays full-bleed.
            <div className={classNames(classes.splitViewPane, boxCanvas ? classes.splitViewPaneBoxed : "")}>
              <CanvasPane messages={messages} boxed={boxCanvas} />
            </div>
          ),
        }}
      />
      {canvasResizable ? (
        <CanvasResizeHandle
          rootRef={splitViewRootRef}
          inset={canvasInset}
          width={pulledCanvasWidth}
          onWidthChange={setPulledCanvasWidth}
          onClose={() => closeCanvas(true)}
        />
      ) : null}
    </div>
  );
};
