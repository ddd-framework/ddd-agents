import { Text } from "../../provider/components";
import type { ChatAssistantCategory, ChatAssistantIdentity, ChatAssistantOpening } from "../chat-assistant-identity";
import { classNames } from "../../provider/class-names";
import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import styles from "./ConversationViewBody.module.scss";
import {
  ConversationMessageInput,
  ConversationMessageInputHandle,
} from "../ConversationMessageInput/ConversationMessageInput";
import { ConversationMessageInputComposerWidgets } from "../ConversationMessageInput/ConversationMessageInputComposerWidgets/ConversationMessageInputComposerWidgets";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetContext";
import type { ConversationAttachmentReference } from "../../contract/conversation";
import { SendMessageHandler } from "../conversation-send-message";
import { ConversationErrorType } from "../store/conversation-error-type";
import type { ChatMessage } from "../chat-message";
import { NoConversationLoading, ConversationLoading } from "../ConversationLoadingStates";
import { ConversationMessageList } from "../ConversationMessageList/ConversationMessageList";
import { WidgetWindowRootContext } from "../../renderer/widget-windowing";
import { ConversationIntroCard } from "../ConversationIntroCard/ConversationIntroCard";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
import { ConversationMessageUserTheme } from "../ConversationMessage/ConversationMessageUser/ConversationMessageUser";
import { ConversationScrollToLatestMessageButton } from "../ConversationScrollToLatestMessageButton/ConversationScrollToLatestMessageButton";
import { useIsMessagesBottomOutOfView } from "./hooks/useIsMessagesBottomOutOfView";
import { usePinQuestionOnSend } from "./hooks/usePinQuestionOnSend";

export interface ConversationViewProps {
  currentCategory?: ChatAssistantCategory; // Type from categories
  aiPromptExtension?: ChatAssistantIdentity; // Type from agents
  suggestions?: ChatAssistantOpening[]; // Type from conversationOpenings
  messages: ChatMessage[] | null; // Type from messages
  aiThinking: boolean;
  error: ConversationErrorType | null;
  /** Retry-After seconds of a 429 quota rejection — forwarded to the error card for the "try again in …" wording. */
  rateLimitRetryAfterSeconds?: number | null;
  /** Host-bound retry of a FAILED run — forwarded to the message list / error card. */
  onRetryFailedRun?: () => Promise<void>;
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
   * A host disables it for a fresh chat that has no messages yet, so the view stays
   * anchored at the top.
   */
  autoScroll?: boolean;
  /**
   * Custom node mounted at the tail of the message list instead of the default
   * (thinking-only) ConversationMessageThinking. Rendered permanently — used by
   * A host to show the animated agent face, which idles between turns.
   */
  thinkingIndicator?: React.ReactNode;
  /**
   * Custom background slot rendered absolutely behind the chat content. When
   * provided, the default chat gradient and the AI-Extensions category
   * illustrations are suppressed so the caller owns the entire background
   * (e.g. A host's shader). Pair with a parent that supplies a solid base color
   * if the slot doesn't paint the full canvas itself.
   */
  background?: React.ReactNode;
  /**
   * Opt-in: the host paints the chat's background itself OUTSIDE this component (e.g. the agent's root shader).
   * Keeps the `customBg` visual mode (top blur band) that passing `background` would give, but with
   * no background node here and a transparent pane fill so the host's layer shows through.
   */
  backgroundPaintedByHost?: boolean;
  /**
   * The setup progress stepper (today's only consumer: the agent chat), mounted as a DIRECT CHILD of this
   * view's pane root — a `position: relative` box the size of the chat pane — and outside the scroll container,
   * so it stays put while messages scroll under it. Mounted here rather than in the host's floating top bar for
   * the same reason the top blur band is (see `.contentWrapper::before`): it has to track the chat content
   * column, so it follows the pane when a canvas or a side panel contracts it, which a viewport-positioned
   * overlay cannot — and that pane boundary exists only inside this view's split.
   *
   * The node brings its OWN positioning, stacking and query container (see the stepper's `.progressStepperRow`);
   * this view contributes nothing but the positioned pane root it is mounted in.
   */
  progressStepperSlot?: React.ReactNode;
  /**
   * Optional external ref to the scrollable content wrapper. Forwarded so
   * callers can drive scroll-linked effects (e.g. an animated background
   * that morphs with scroll position) without having to query the DOM.
   */
  scrollRef?: React.Ref<HTMLDivElement>;
  /**
   * Bump to force a scroll to the newest message (via {@link scrollToLatestMessage}, so it clears any pinned turn and
   * re-enables bottom-follow). Each distinct value triggers one scroll; the initial `0`/`undefined` is ignored. Used by
   * the agent to follow the turn a widget/CTA-click signal starts (which posts no visible message of its own).
   */
  scrollToBottomNonce?: number;
  /**
   * Optional external override of the internal bottom-follow flag. Provide it when the host runs its own programmatic
   * scroll away from the bottom (e.g. the agent's search-result jump, which pages older messages in and scrolls to
   * a message far above the bottom): the host sets it `false` for the jump so the resize/message-follow effects stop
   * re-anchoring to the bottom and yanking the view back mid-jump. Left undefined, ConversationViewBody uses its own ref and
   * behaves exactly as before. Shared with the AW-107 pin machinery, so both agree on one flag.
   */
  followBottomRef?: React.MutableRefObject<boolean>;
  /**
   * Optional "a programmatic jump is in flight" gate. Set by the host (the agent's search-result jump) for the duration of a
   * jump. While true, the near-bottom onScroll handler does NOT re-enable bottom-follow and the resize ResizeObserver
   * does NOT auto-scroll to the bottom — otherwise, right after the jump the container is still near the bottom, so
   * onScroll would flip follow back on and the follow-up content-resize would yank the view back down. Left undefined,
   * nothing is gated. Distinct from `followBottomRef`: that flag alone is clobbered by onScroll; this gate stops it.
   */
  scrollJumpActiveRef?: React.MutableRefObject<boolean>;
  /**
   * Hide the assistant header (icon + name + "Assistant") and the copy button
   * on assistant messages. Used by a host, which renders bare messages.
   */
  hideAssistantMeta?: boolean;
  /**
   * Theme for user-message bubbles, forwarded to ConversationMessageList. A host forces
   * "dark"; when omitted the list falls back to its legacy light/default logic.
   */
  userMessageTheme?: ConversationMessageUserTheme;
  /**
   * True when the host (the agent) is minimized to the compact side-panel; forwarded to ConversationViewBody.
   */
  isCompact?: boolean;
  /**
   * Opt-in (agent host only): confine the customBg top blur band to the chat pane instead of the whole viewport —
   * active only while a sibling side panel is docked beside the chat, so the band compresses together with it instead
   * of bleeding across the panel. See `.confineTopBlurBandToChatPane` below.
   */
  confineTopBlurBandToChatPane?: boolean;
  /**
   * Scroll-up older-message pagination (opt-in — mirrors the admin inspector). When `onLoadOlder` is provided, a top
   * sentinel + IntersectionObserver (root = the scroll container) prefetches the next older page ~300px before the
   * top, and the viewport is anchored across the prepend so it doesn't jump. `hasMoreOlder` gates the sentinel;
   * `loadingOlder` shows a small loading row. Left undefined (the default) → no pagination, behaviour unchanged.
   */
  hasMoreOlder?: boolean;
  loadingOlder?: boolean;
  onLoadOlder?: () => void;
  /**
   * Extra action controls rendered inside the chat input's action row, left of the send button —
   * forwarded to ConversationMessageInput (e.g. the agent model picker pill).
   */
  inputActionsSlot?: React.ReactNode;
  /**
   * Action controls pinned to the LEFT edge of the chat input's action row (`inputActionsSlot`
   * stays right) — forwarded to ConversationMessageInput's `actionsSlotStart` (e.g. the agent "+" attachments menu).
   */
  inputActionsSlotStart?: React.ReactNode;
  /**
   * Host-owned content rendered inside the chat input above its editable text area — attachment
   * preview cards, validation error boxes. Forwarded to ConversationMessageInput's `topSlot`.
   */
  inputTopSlot?: React.ReactNode;
  /**
   * Hard send gate for the chat input: blocks the send button AND Enter while true, but keeps the
   * text editable. The host sets it e.g. while attachment uploads run.
   */
  inputSendDisabled?: boolean;
  /**
   * Host-owned content rendered IN PLACE of the chat input — the closed-conversation bar of a completed
   * setup conversation (no further input possible). When set, the body renders it instead of ConversationMessageInput,
   * in a wrapper carrying the input's own width, bottom gap and stacking rule — so the node needs no layout of its
   * own, and the background layer cannot paint over it.
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
   * send. Owned and cleared by the host; forwarded to ConversationMessageInput.
   */
  outgoingAttachmentImages?: ConversationAttachmentReference[];
  /**
   * Imperative handle on the chat input (insertTextIntoInput) so host-owned controls — e.g. the
   * "+" menu's "Generate image" entry — can put text into it. Forwarded to ConversationMessageInput's `apiRef`.
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
   * Opt-in long-answer reading flow (AW-107), default false. When enabled:
   * (1) on send, the question is pinned to the top with room reserved below it so the reply
   *     reveals from its beginning instead of chasing the bottom;
   * (2) a scroll-to-latest-message button floats above the input while the bottom is out of view;
   * (3) a fresh static reply's markdown blocks fade in staggered (useFreshMessageBlockReveal).
   * Left off, the chat keeps the classic bottom-follow behavior — AI Extensions stays off;
   * the agent chat opts in.
   */
  enablePinQuestionAndRevealAnswer?: boolean;
  /** New user messages are not sends (a voice chat saves the owner's spoken sentences): keep bottom-follow, no pin. */
  isQuestionPinSuspended?: boolean;
  /**
   * Id of the ONE user message ConversationMessageList renders as a collapsed task-instruction disclosure instead of an
   * owner bubble — the machine-composed seed instruction of a SCHEDULE-spawned run (UX overhaul W4 item 19).
   * Undefined (every host except the agent chat) changes nothing.
   */
  taskInstructionMessageId?: number;
  /**
   * Host content rendered right after one message of the thread, forwarded to ConversationMessageList (the agent chat's
   * activity card under the message that started each turn). Undefined changes nothing.
   */
  renderAfterMessage?: (message: ChatMessage) => React.ReactNode;
  /** Forwarded to ConversationMessageList: a closed conversation renders no widget controls. */
  isConversationClosed?: boolean;
  onSuggestionClick: (text: string) => void;
  onSendMessage: SendMessageHandler;
}

export const ConversationViewBody = (props: ConversationViewProps) => {
  const {
    currentCategory,
    aiPromptExtension,
    suggestions,
    messages,
    aiThinking,
    error,
    rateLimitRetryAfterSeconds,
    onRetryFailedRun,
    isDebugMode,
    categoriesLoaded,
    startingNewConversation,
    conversationId,
    showMessagesWithoutConversation,
    autoScroll = true,
    thinkingIndicator,
    background,
    backgroundPaintedByHost,
    progressStepperSlot,
    scrollRef,
    scrollToBottomNonce,
    followBottomRef,
    scrollJumpActiveRef,
    hideAssistantMeta,
    userMessageTheme,
    isCompact,
    confineTopBlurBandToChatPane,
    hasMoreOlder,
    loadingOlder,
    onLoadOlder,
    inputActionsSlot,
    inputActionsSlotStart,
    inputTopSlot,
    inputSendDisabled,
    inputReplacementSlot,
    inputBandSlot,
    inputPlaceholder,
    inputSendReplacementWhenEmpty,
    inputGlowUnderlay,
    contentOverlaySlot,
    isContentOverlayShown = false,
    outgoingAttachmentImages,
    conversationMessageInputApiRef,
    allowSendWhileAiThinking,
    enablePinQuestionAndRevealAnswer = false,
    isQuestionPinSuspended = false,
    taskInstructionMessageId,
    renderAfterMessage,
    isConversationClosed,
    onSuggestionClick,
    onSendMessage,
  } = props;

  // Composer widgets ride the widget context itself (like the deleted selection chip did), so every
  // ConversationView host gets the strip, the outgoing-on-send value and the clear-on-send wiring for free.
  const { composerWidgets, clearComposerWidgets } = useConversationWidgetContext();

  const messagesBottom = useRef<HTMLDivElement | null>(null);
  const [scrollContainer, setScrollContainer] = useState<HTMLDivElement | null>(null);
  const [messagesList, setMessagesList] = useState<HTMLDivElement | null>(null);
  // Bottom-follow flag. Uses the host-supplied ref when given (so a host-driven jump can suspend follow, see
  // `followBottomRef` in props), otherwise a local one — identical behaviour when no host overrides it.
  const internalFollowBottom = useRef(true);
  const followBottom = followBottomRef ?? internalFollowBottom;
  // Rendered USER-row count at the last positioning pass — an increase within the same conversation means the user
  // just sent (the pin trigger). Counted from the DOM (not the messages array) so hidden USER messages (/toolresult
  // reports, SIGNAL/ERROR) never miscount and it always matches the row pinLatestQuestionToTop targets.
  const prevUserMessageCount = useRef(0);
  // Last conversationId the positioning effect saw, to tell a real switch / new-chat (re-anchor to bottom) apart from
  // the cold-start id landing mid-turn (undefined → a freshly-created id while pinned → keep the pin).
  const prevConversationId = useRef<number | undefined>(conversationId);
  // Scroll-up pagination (opt-in via onLoadOlder). Capturing the scrollHeight when an older-load starts lets the
  // prepend layout-effect restore the viewport so it doesn't jump when the older page merges in.
  const prependAnchorScrollHeight = useRef<number | null>(null);

  // Combined ref callback for the scrollable content wrapper: drives the
  // internal state ref (the auto-scroll / follow-bottom effects depend on it)
  // AND forwards to the caller-supplied `scrollRef` when present. Stable identity
  // so the ref isn't detached/reattached on every render.
  const handleContentWrapperRef = useCallback(
    (el: HTMLDivElement | null) => {
      setScrollContainer(el);
      if (typeof scrollRef === "function") scrollRef(el);
      else if (scrollRef) (scrollRef as React.MutableRefObject<HTMLDivElement | null>).current = el;
    },
    [scrollRef],
  );

  // The named assistant's theme and background come from the app; the agent chat shows none.
  const { readAssistantThemeClassName, AssistantBackground } = useAgentsEnvironment();
  const colorPalette = useMemo(
    () =>
      currentCategory?.alias
        ? (readAssistantThemeClassName?.(currentCategory.alias) ?? styles.genericChat)
        : styles.genericChat,
    [currentCategory, readAssistantThemeClassName],
  );

  const isMessageListVisible =
    (!!messages && (!!conversationId || !!showMessagesWithoutConversation)) ||
    startingNewConversation ||
    (!!error && error !== ConversationErrorType.CONVERSATION_NOT_FOUND);

  const isConversationIntroVisible = useMemo(
    () =>
      aiPromptExtension !== undefined &&
      !conversationId &&
      !startingNewConversation &&
      !messages &&
      !(!!error && error !== ConversationErrorType.CONVERSATION_NOT_FOUND),
    [aiPromptExtension, conversationId, startingNewConversation, messages, error],
  );

  // The list DOM node. Prefer the state ref (it drives the ResizeObserver effect); fall back to the bottom marker's
  // parent, which is always the .chatMessages list — needed in the layout effect on the render where the list first
  // mounts (setMessagesList hasn't flushed to state yet, but messagesBottom's ref is already committed).
  const getMessagesListEl = useCallback(
    (): HTMLElement | null => messagesList ?? messagesBottom.current?.parentElement ?? null,
    [messagesList],
  );

  const {
    pinLatestQuestionToTop,
    reserveRoomBelowPinnedQuestion,
    isQuestionPinnedThisTurn,
    endPinnedTurn,
    userMessageRowCount,
  } = usePinQuestionOnSend({
    enabled: enablePinQuestionAndRevealAnswer,
    scrollContainer,
    getMessagesListEl,
    messagesBottomRef: messagesBottom,
    followBottomRef: followBottom,
  });

  // First positioning is instant and pre-paint (layout effect + behavior "auto"):
  // opening a conversation lands directly at the bottom with no visible scroll
  // animation. Subsequent message updates scroll smoothly.
  const hasPositionedOnce = useRef(false);
  useLayoutEffect(() => {
    // Older-page prepend (scroll-up pagination): restore the viewport so the freshly-prepended messages don't shift
    // it, and do NOT auto-scroll to the bottom. The anchor is only set when an older-load started, so a conversation
    // switch / live tick never enters this branch.
    if (prependAnchorScrollHeight.current !== null) {
      if (scrollContainer) {
        const delta = scrollContainer.scrollHeight - prependAnchorScrollHeight.current;
        if (delta > 0) scrollContainer.scrollTop += delta;
      }
      prependAnchorScrollHeight.current = null;
      return;
    }
    if (!autoScroll) {
      hasPositionedOnce.current = false;
      return;
    }

    // Addon off (default): the classic bottom-follow — re-anchor to the bottom on every message update, exactly the
    // pre-AW-107 behavior (including the old quirk that it scrolls even before the list mounts, no messagesBottom guard).
    if (!enablePinQuestionAndRevealAnswer) {
      followBottom.current = true;
      messagesBottom.current?.scrollIntoView({
        behavior: hasPositionedOnce.current ? "smooth" : "auto",
        block: "end",
        inline: "nearest",
      });
      hasPositionedOnce.current = true;
      return;
    }

    // A real conversation switch / new-chat / first history load re-anchors to the bottom. The one exception is the
    // cold-start id landing (undefined → a freshly-created id) MID-TURN: the question was already pinned on send, so
    // keep it pinned instead of yanking back to the bottom.
    const conversationChanged = prevConversationId.current !== conversationId;
    const isColdStartIdLanding = prevConversationId.current === undefined && isQuestionPinnedThisTurn();
    prevConversationId.current = conversationId;
    if (conversationChanged && !isColdStartIdLanding) {
      endPinnedTurn();
      followBottom.current = true;
      hasPositionedOnce.current = false;
    }

    const userCount = userMessageRowCount();

    // First positioning for this conversation. If the list isn't mounted yet (still loading / intro screen), wait for
    // it. A fresh cold-start send (startingNewConversation, nothing positioned yet) pins the first question to the top;
    // opening an existing conversation lands at the bottom, as today.
    if (!hasPositionedOnce.current) {
      if (!messagesBottom.current) return; // list not in the DOM yet — nothing to position
      hasPositionedOnce.current = true;
      prevUserMessageCount.current = userCount;
      if (startingNewConversation && userCount > 0) {
        pinLatestQuestionToTop("auto");
      } else {
        followBottom.current = true;
        messagesBottom.current.scrollIntoView({ behavior: "auto", block: "end", inline: "nearest" });
      }
      return;
    }

    // Same conversation, already positioned: a new USER row means the user just sent → pin the new question to the top.
    if (userCount > prevUserMessageCount.current && !isQuestionPinSuspended) {
      prevUserMessageCount.current = userCount;
      pinLatestQuestionToTop("smooth");
      return;
    }
    prevUserMessageCount.current = userCount;

    // A later update within the same turn (the reply arriving / growing): keep the question pinned and just re-reserve
    // room — never scroll. Outside a pinned turn (e.g. a background refresh) keep today's bottom-follow.
    if (isQuestionPinnedThisTurn()) {
      reserveRoomBelowPinnedQuestion();
    } else if (followBottom.current) {
      messagesBottom.current?.scrollIntoView({ behavior: "smooth", block: "end", inline: "nearest" });
    }
  }, [
    messages,
    autoScroll,
    scrollContainer,
    conversationId,
    startingNewConversation,
    enablePinQuestionAndRevealAnswer,
    isQuestionPinSuspended,
    userMessageRowCount,
    reserveRoomBelowPinnedQuestion,
    pinLatestQuestionToTop,
    isQuestionPinnedThisTurn,
    endPinnedTurn,
    followBottom,
  ]);

  // Capture the scroll height the instant an older-load starts — consumed by the prepend layout-effect above to keep
  // the viewport anchored once the older page merges in.
  useEffect(() => {
    if (loadingOlder && scrollContainer) {
      prependAnchorScrollHeight.current = scrollContainer.scrollHeight;
    }
  }, [loadingOlder, scrollContainer]);

  // Scroll-up lazy-load: prefetch the next older page when the user scrolls within ~300px of the top. onLoadOlder is
  // self-guarded (in-flight + hasMoreOlder) by the caller, so repeated scroll ticks near the top don't double-fetch.
  // No-op unless the caller opts in via onLoadOlder.
  useEffect(() => {
    if (!onLoadOlder || !scrollContainer || !hasMoreOlder) return undefined;
    const onScrollNearTop = () => {
      if (scrollContainer.scrollTop <= 300) onLoadOlder();
    };
    scrollContainer.addEventListener("scroll", onScrollNearTop, { passive: true });
    return () => scrollContainer.removeEventListener("scroll", onScrollNearTop);
  }, [onLoadOlder, scrollContainer, hasMoreOlder]);

  useEffect(() => {
    if (!autoScroll) return;
    const container = scrollContainer;
    const list = messagesList;
    if (!container || !list) return;

    const NEAR_BOTTOM_PX = 80;
    const isNearBottom = () => container.scrollHeight - container.scrollTop - container.clientHeight <= NEAR_BOTTOM_PX;

    let animRaf: number | null = null;
    let isProgrammaticScroll = false;
    const cancelAnim = () => {
      if (animRaf !== null) {
        cancelAnimationFrame(animRaf);
        animRaf = null;
      }
    };

    // Non-interruptible smooth scroll: if already running, the existing
    // animation just re-reads the target each frame and chases it — no
    // restart, no blink. Only user interaction or unmount cancels it.
    const startSmoothToBottom = (durationMs: number) => {
      if (animRaf !== null) return; // in-flight; target re-read each frame
      const start = container.scrollTop;
      const initialTarget = container.scrollHeight - container.clientHeight;
      if (initialTarget - start <= 0) return;
      const t0 = performance.now();
      const easeOut = (t: number) => 1 - Math.pow(1 - t, 3);
      const tick = (now: number) => {
        const t = Math.min(1, (now - t0) / durationMs);
        const target = container.scrollHeight - container.clientHeight;
        isProgrammaticScroll = true;
        container.scrollTop = start + (target - start) * easeOut(t);
        isProgrammaticScroll = false;
        if (t < 1) {
          animRaf = requestAnimationFrame(tick);
        } else {
          animRaf = null;
        }
      };
      animRaf = requestAnimationFrame(tick);
    };

    const disableFollow = () => {
      followBottom.current = false;
      // A pinned turn (AW-107) keeps its pin + reserved room across a manual scroll — we deliberately do NOT clear them
      // here. Dropping the padding on scroll shrank the scroll height and made the viewport jump; and the empty space
      // below a short reply is intentional, so the question holds its place at the top for the whole turn.
      cancelAnim();
    };
    const onScroll = () => {
      if (isProgrammaticScroll) return;
      // A host-driven jump is in flight: don't re-enable follow just because the viewport is still/again near the
      // bottom — that clobbers the jump and lets the resize-follow yank the view back down.
      if (scrollJumpActiveRef?.current) return;
      if (isNearBottom()) followBottom.current = true;
    };

    container.addEventListener("wheel", disableFollow, { passive: true });
    container.addEventListener("touchmove", disableFollow, { passive: true });
    container.addEventListener("keydown", disableFollow);
    container.addEventListener("scroll", onScroll, { passive: true });

    const observer = new ResizeObserver(() => {
      // A host-driven jump is in flight (search-result jump): the messages settle their heights as they render, which
      // fires this observer — do NOT auto-scroll to the bottom, or the jump gets yanked back down.
      if (scrollJumpActiveRef?.current) return;
      // During a pinned turn the reply grows below the fold: re-reserve room (it shrinks to 0 as the reply fills the
      // viewport) — never scroll, so the question stays put at the top. Inert when the addon is off (pin never set).
      if (isQuestionPinnedThisTurn()) {
        reserveRoomBelowPinnedQuestion();
        return;
      }
      if (!followBottom.current) return;
      startSmoothToBottom(180);
    });
    observer.observe(list);
    // Also react to viewport/pane height changes (window resize, drawer open/close): the list observer won't fire when
    // only the container's height changes, but a pinned turn's reserved room depends on container.clientHeight. Only
    // needed for the addon — leaving it on for the default chat would newly trigger scroll-to-bottom on drawer toggle.
    if (enablePinQuestionAndRevealAnswer) observer.observe(container);

    return () => {
      container.removeEventListener("wheel", disableFollow);
      container.removeEventListener("touchmove", disableFollow);
      container.removeEventListener("keydown", disableFollow);
      container.removeEventListener("scroll", onScroll);
      observer.disconnect();
      cancelAnim();
    };
  }, [
    isMessageListVisible,
    scrollContainer,
    messagesList,
    autoScroll,
    enablePinQuestionAndRevealAnswer,
    isQuestionPinnedThisTurn,
    reserveRoomBelowPinnedQuestion,
    followBottom,
    scrollJumpActiveRef,
  ]);

  // Only observe the sentinel when the addon is on — a null container makes the hook return false + mount no observer.
  const isMessagesBottomOutOfView = useIsMessagesBottomOutOfView(
    enablePinQuestionAndRevealAnswer ? scrollContainer : null,
    messagesList,
    messagesBottom,
  );

  // Jump back to the newest message. Ends a pinned turn: the user is explicitly leaving the pinned
  // question, so bottom-follow takes over again (the ResizeObserver's pinned branch would otherwise
  // keep suppressing it, ConversationViewBody.tsx:396). Dropping the reserved padding here cannot jump the
  // viewport: the button only shows when the viewport is ≥300px ABOVE the sentinel, and the padding
  // lives entirely BELOW it — shrinking scrollHeight below the viewport doesn't move scrollTop.
  const scrollToLatestMessage = useCallback(() => {
    endPinnedTurn();
    followBottom.current = true;
    messagesBottom.current?.scrollIntoView({ behavior: "smooth", block: "end", inline: "nearest" });
  }, [endPinnedTurn, followBottom]);

  // Caller-driven scroll-to-bottom (scrollToBottomNonce bumped per widget/CTA-click signal). Goes through
  // scrollToLatestMessage so it clears any pinned turn and re-enables bottom-follow — a raw scrollTo would be
  // suppressed while a turn is pinned. Ignores the initial 0/undefined so it never scrolls on mount.
  useEffect(() => {
    if (!scrollToBottomNonce) return;
    scrollToLatestMessage();
  }, [scrollToBottomNonce, scrollToLatestMessage]);

  const messageColumn = (
    <div
      className={styles.contentWrapper}
      // A stable hook for a host's own stylesheet (the default chat drops the top blur band, it has no top bar).
      data-agents-chat-scroll=""
      data-testid="chat-messages-scroll"
      ref={handleContentWrapperRef}
      aria-hidden={isContentOverlayShown || undefined}
      inert={isContentOverlayShown || undefined}
    >
      {!conversationId && !categoriesLoaded && !aiPromptExtension ? (
        <NoConversationLoading />
      ) : null}

      {!!conversationId && !messages ? (
        <ConversationLoading />
      ) : null}

      {isConversationIntroVisible ? (
        <ConversationIntroCard
          aiPromptExtension={aiPromptExtension!}
          aiPromptExtensionIcon={aiPromptExtension!.iconClass as string}
          suggestions={suggestions}
          onSuggestionClick={onSuggestionClick}
        />
      ) : null}

      {!!loadingOlder ? (
        <span className={styles.olderLoading}>
          <Text>{"Loading older messages…"}</Text>
        </span>
      ) : null}

      {isMessageListVisible ? (
        <>
          {/* The scroll container (this contentWrapper) is the IntersectionObserver root for widget viewport-windowing. */}
          <WidgetWindowRootContext.Provider value={scrollContainer}>
            <ConversationMessageList
              className={
                enablePinQuestionAndRevealAnswer && (conversationId || startingNewConversation || aiThinking || error)
                  ? styles.topAlignedMessages
                  : undefined
              }
              messages={messages}
              currentCategory={currentCategory}
              aiPromptExtension={aiPromptExtension}
              isDebugMode={isDebugMode}
              aiThinking={aiThinking}
              thinkingIndicator={thinkingIndicator}
              hideAssistantMeta={hideAssistantMeta}
              userMessageTheme={userMessageTheme}
              error={error}
              rateLimitRetryAfterSeconds={rateLimitRetryAfterSeconds}
              onRetryFailedRun={onRetryFailedRun}
              messagesBottomRef={messagesBottom}
              messagesListRef={setMessagesList}
              enableFreshMessageBlockReveal={enablePinQuestionAndRevealAnswer}
              taskInstructionMessageId={taskInstructionMessageId}
              renderAfterMessage={renderAfterMessage}
              isConversationClosed={isConversationClosed}
            />
          </WidgetWindowRootContext.Provider>
        </>
      ) : null}
    </div>
  );

  return (
    <div
      className={classNames(
        styles.chat,
        background || backgroundPaintedByHost ? styles.customBg : colorPalette,
        backgroundPaintedByHost ? styles.backgroundPaintedByHost : undefined,
        isCompact ? styles.chatCompact : undefined,
        confineTopBlurBandToChatPane ? styles.confineTopBlurBandToChatPane : undefined,
      )}
    >
      {background ? (
        <div className={styles.backgroundLayer}>{background}</div>
      ) : backgroundPaintedByHost ? null : (
        AssistantBackground ? (
          <AssistantBackground category={currentCategory} categoryAlias={currentCategory?.alias ?? undefined} />
        ) : null
      )}

      {/* Positions itself against this .chat root (see the prop's doc) — no wrapper, no class from here. */}
      {progressStepperSlot}

      {contentOverlaySlot !== undefined ? (
        <div className={styles.contentLayers} data-overlay-shown={isContentOverlayShown || undefined}>
          {messageColumn}
          <div
            className={styles.contentOverlay}
            aria-hidden={!isContentOverlayShown || undefined}
            inert={!isContentOverlayShown || undefined}
          >
            {contentOverlaySlot}
          </div>
        </div>
      ) : (
        messageColumn
      )}
      {enablePinQuestionAndRevealAnswer && (
        <div className={styles.scrollToLatestAnchor}>
          <ConversationScrollToLatestMessageButton
            visible={isMessagesBottomOutOfView}
            onClick={scrollToLatestMessage}
          />
        </div>
      )}
      {/* With allowSendWhileAiThinking the input stays open during a turn, EXCEPT while the cold-start
          startWithMessage POST is in flight: no conversation id exists yet, so the host's send handler would drop a
          second message silently — keep the brief lock for exactly that window. */}
      {inputReplacementSlot ? (
        <div className={styles.inputReplacement}>{inputReplacementSlot}</div>
      ) : (
        <>
          {inputBandSlot}
          <ConversationMessageInput
            className={classNames(styles.input, currentCategory?.alias ? styles.inputInCategory : undefined)}
            isProcessing={allowSendWhileAiThinking ? !!startingNewConversation : aiThinking}
            onSendMessage={onSendMessage}
            actionsSlot={inputActionsSlot}
            actionsSlotStart={inputActionsSlotStart}
            // The composer-widgets strip is one topSlot block like any other — above the host's own blocks
            // (attachment previews sit closest to the text). It reads the widget context itself, so every
            // ConversationView host gets it for free.
            topSlot={
              <>
                <ConversationMessageInputComposerWidgets testId="chat-input" />
                {inputTopSlot}
              </>
            }
            sendDisabled={inputSendDisabled}
            outgoingAttachmentImages={outgoingAttachmentImages}
            outgoingComposerWidgets={composerWidgets}
            onComposerWidgetsSent={clearComposerWidgets}
            apiRef={conversationMessageInputApiRef}
            placeholder={inputPlaceholder}
            sendReplacementWhenEmpty={inputSendReplacementWhenEmpty}
            glowUnderlay={inputGlowUnderlay}
          />
        </>
      )}
    </div>
  );
};
