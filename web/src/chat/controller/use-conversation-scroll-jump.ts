import { RefObject, useCallback, useEffect, useRef } from "react";
import type { ChatMessage } from "../chat-message";
import type { ChatScrollTarget } from "./chat-scroll-target";

/** What the scroll follow reads of the activity ticker: its phase and the lines of the turn on screen. */
export interface ConversationScrollActivityTicker {
  phase: string;
  currentTurn: { lines: { toolCallMessageId: number; state: string | null }[] } | null;
}

export interface ConversationScrollJumpInputs {
  /** The top-bar search's request to scroll a message into view and flash it; re-applied whenever its nonce changes. */
  scrollTarget: ChatScrollTarget | undefined;
  /** The conversation the host asked for (the chat's `conversationId` prop) — the one a request is armed for. */
  conversationId: number | undefined;
  /** The conversation the chat store holds right now; differs from `conversationId` for a beat after a switch. */
  conversation: { id?: number | null } | null;
  /** The loaded messages: every change retries a pending request. */
  messages: ChatMessage[] | null;
  /** The loaded messages as of the last render, read where the retry runs. */
  messagesRef: RefObject<ChatMessage[] | null>;
  /** Whether an older page exists, as of the last render. */
  hasMoreOlderRef: RefObject<boolean>;
  /** Pages the next older page in (self-guarded against concurrent and exhausted loads). */
  loadOlder: () => Promise<void>;
  /** The chat's scroll container (the host may own it, e.g. for a scroll-linked background). */
  chatScrollRef: RefObject<HTMLDivElement | null>;
  /** What the activity ticker shows; a new line scrolls it into view while the chat follows the bottom. */
  activityTicker: ConversationScrollActivityTicker;
}

export interface ConversationScrollJump {
  /** Bottom-follow flag shared with ConversationViewBody; false while a jump is armed and after it. */
  followBottomRef: RefObject<boolean>;
  /** "A programmatic jump is in flight" gate shared with ConversationViewBody. */
  scrollJumpActiveRef: RefObject<boolean>;
}

/**
 * The chat's programmatic scrolling: the jump to a requested message (paging older pages in until it is rendered, then
 * the flash once the smooth scroll settled) and keeping the activity ticker in view as it grows. Owns the two refs it
 * shares with ConversationViewBody, which suspend the bottom-follow while a jump is armed.
 */
export const useConversationScrollJump = (inputs: ConversationScrollJumpInputs): ConversationScrollJump => {
  const {
    scrollTarget,
    conversationId,
    conversation,
    messages,
    messagesRef,
    hasMoreOlderRef,
    loadOlder,
    chatScrollRef,
    activityTicker,
  } = inputs;

  // Scroll-to-message (top-bar search → Agent → here). The requested hit is held in a ref until its message is
  // actually in the DOM: if it sits on an older page that hasn't loaded yet, page older in and retry on each
  // `messages` change. `data-message-id` is stamped on every rendered message by ConversationMessageList; the flash is a
  // transient `data-flash` attribute styled in ConversationMessageList's stylesheet.
  // `armedForConversationId` stamps which conversation the request targeted, so a request that outlives its
  // conversation (e.g. the selected search hit's conversation 404s) can't page through an unrelated one later.
  const pendingScrollRef = useRef<(ChatScrollTarget & { armedForConversationId?: number }) | null>(null);
  // Bottom-follow flag shared with ConversationViewBody. A search-result jump scrolls to a message ABOVE the bottom, then the
  // messages settle their heights (images/markdown) which fires ConversationViewBody's resize bottom-follow. While a jump is
  // armed we hold this false so follow stays suspended for the whole jump (and afterwards — the user is now reading up
  // there, exactly as a manual scroll-up).
  const followBottomRef = useRef(true);
  // "A programmatic jump is in flight" gate, shared with ConversationViewBody. Distinct from followBottomRef because the jump
  // must ALSO stop ConversationViewBody's near-bottom onScroll handler from flipping followBottom back to true: right after the
  // click the container is still near the bottom (the smooth scrollIntoView hasn't physically moved yet), so onScroll
  // would re-enable follow and the resize-follow would then yank the view back down. While this is true ConversationViewBody
  // neither re-enables follow on scroll nor auto-scrolls to the bottom on resize. Cleared once the jump settles/disarms.
  const scrollJumpActiveRef = useRef(false);

  // Keep the activity ticker in view as it grows — a new line beside the face or a new row above it scrolls it
  // into view with the exact guarded pattern the search-result jump above uses (chatScrollRef query +
  // scrollIntoView), not a second scroller. Keyed on what the ticker SHOWS, not on the endpoint's elements: the ticker
  // releases lines one at a time after they arrive. Skipped while the user has scrolled away (followBottomRef false)
  // or a host jump is already in flight (scrollJumpActiveRef) — the scroll-to-latest button already covers that case,
  // exactly as it does for a growing final answer under the pinned-question addon.
  // `block: "nearest"`, never "end": during a pinned turn (enablePinQuestionAndRevealAnswer) the room reserved below
  // the question makes the pinned position the scroll bottom, so ConversationViewBody's near-bottom onScroll turns
  // followBottomRef back on. "end" then aligned the ticker with the viewport bottom — scrolling UP by the reserved room
  // and dropping the question off the top. "nearest" only scrolls once the ticker actually runs below the fold.
  const activitySignatureRef = useRef("");
  useEffect(() => {
    const { phase, currentTurn } = activityTicker;
    const newestLine = currentTurn?.lines[currentTurn.lines.length - 1];
    const signature = `${phase}|${currentTurn?.lines.length}|${newestLine?.toolCallMessageId}:${newestLine?.state}`;
    if (signature === activitySignatureRef.current) return;
    activitySignatureRef.current = signature;
    if (phase !== "live" || !followBottomRef.current || scrollJumpActiveRef.current) return;
    const tickerElement = chatScrollRef.current?.querySelector<HTMLElement>('[data-testid="agent-activity-ticker"]');
    tickerElement?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
  }, [activityTicker, chatScrollRef, followBottomRef, scrollJumpActiveRef]);

  // Tracks the in-flight "remove the flash highlight" timer so it can be cancelled if a new scroll lands first or the
  // widget unmounts mid-flash (otherwise the callback fires on a detached node).
  const flashTimerRef = useRef<number | null>(null);
  // Tracks the requestAnimationFrame loop that waits for the smooth scroll to settle before the flash starts, so a new
  // scroll request or an unmount can cancel a still-pending wait.
  const scrollSettleRafRef = useRef<number | null>(null);
  const tryScrollToPendingMessage = useCallback(() => {
    const target = pendingScrollRef.current;
    if (!target?.messageId) return;
    // The conversation the request was armed for is gone (load failed / user navigated elsewhere) — disarm rather
    // than hunting a foreign message id through whatever conversation is shown now.
    if (target.armedForConversationId !== conversationId) {
      pendingScrollRef.current = null;
      scrollJumpActiveRef.current = false;
      return;
    }
    // A jump is armed for the current conversation → suspend bottom-follow AND gate ConversationViewBody's follow-re-enable /
    // resize-follow for the whole jump. Re-asserted on every retry (each older-page merge), so even a conversation
    // switch that briefly re-enabled follow can't yank us down.
    followBottomRef.current = false;
    scrollJumpActiveRef.current = true;
    const messageElement = chatScrollRef.current?.querySelector<HTMLElement>(`[data-message-id="${target.messageId}"]`);
    if (messageElement) {
      pendingScrollRef.current = null;
      // Cancel any flash still in flight from a previous jump (its removal timer + settle watcher) and clear the
      // attribute so the animation can restart cleanly on this message.
      if (flashTimerRef.current !== null) window.clearTimeout(flashTimerRef.current);
      if (scrollSettleRafRef.current !== null) window.cancelAnimationFrame(scrollSettleRafRef.current);
      messageElement.removeAttribute("data-flash");

      messageElement.scrollIntoView({ behavior: "smooth", block: "center" });

      // Start the flash only AFTER the smooth scroll settles, so the highlight plays on the at-rest message rather
      // than during the travel. Watch the element's viewport position each frame: once it holds still for 3
      // consecutive frames (covers both "scrolled then stopped" and "already in view"), or a 1.2s safety cap elapses,
      // the scroll has ended → toggle `data-flash` and arm its ~2s removal.
      let lastTop = messageElement.getBoundingClientRect().top;
      let stillFrames = 0;
      const startedAt = performance.now();
      const waitForScrollEnd = () => {
        if (!messageElement.isConnected) {
          scrollSettleRafRef.current = null;
          scrollJumpActiveRef.current = false;
          return;
        }
        const top = messageElement.getBoundingClientRect().top;
        stillFrames = Math.abs(top - lastTop) < 0.5 ? stillFrames + 1 : 0;
        lastTop = top;
        if (stillFrames >= 3 || performance.now() - startedAt > 1200) {
          scrollSettleRafRef.current = null;
          // The jump has come to rest → lift the gate. followBottomRef stays false, so a later content-resize won't
          // yank to the bottom; the user is parked at the target, exactly as after a manual scroll-up.
          scrollJumpActiveRef.current = false;
          messageElement.setAttribute("data-flash", "true");
          flashTimerRef.current = window.setTimeout(() => {
            flashTimerRef.current = null;
            if (messageElement.isConnected) messageElement.removeAttribute("data-flash");
          }, 2000);
          return;
        }
        scrollSettleRafRef.current = window.requestAnimationFrame(waitForScrollEnd);
      };
      scrollSettleRafRef.current = window.requestAnimationFrame(waitForScrollEnd);
      return;
    }
    // Conversation just switched (e.g. a cross-conversation search hit selected in the current tab): the slice still
    // holds the PREVIOUS conversation's messages/hasMoreOlder for a beat before the new fetch resolves. Evaluating
    // against that stale data below would misjudge "exhausted" and disarm the request too early — wait instead for the
    // messages-change effect to retry once the new conversation's own data actually lands.
    if (String(conversation?.id) !== String(conversationId) || messagesRef.current === null) return;
    // Not rendered yet — if the target is older than the oldest loaded message, page older in; the messages-change
    // effect below retries once that page merges. loadOlder self-guards against concurrent / exhausted loads.
    const lowestLoadedIndex = messagesRef.current[0]?.indexInConversation;
    const targetIsOlderThanLoaded =
      target.indexInConversation == null || lowestLoadedIndex == null || target.indexInConversation < lowestLoadedIndex;
    if (hasMoreOlderRef.current && targetIsOlderThanLoaded) {
      void loadOlder();
      return;
    }
    // Exhausted every older page and still no match (e.g. a deleted/stale message id) — disarm so we don't keep
    // re-querying on every future message change and can't later jump to an unrelated message that reuses the id.
    pendingScrollRef.current = null;
    scrollJumpActiveRef.current = false;
  }, [chatScrollRef, loadOlder, conversation, conversationId]);

  // A new scroll request (nonce changed) → arm it and attempt immediately.
  useEffect(() => {
    if (!scrollTarget?.messageId) return;
    // conversationId is read from this render's closure: Agent sets the target conversation and the scroll
    // request in the same state update, so at arm time the prop already names the conversation the hit belongs to.
    pendingScrollRef.current = { ...scrollTarget, armedForConversationId: conversationId };
    tryScrollToPendingMessage();
    // This effect closes over the nonce-triggering render's current conversation and pending message. The nonce bump
    // is deliberately the sole re-run trigger; it reads those current values when it fires.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scrollTarget?.nonce]);

  // Cancel a pending flash-removal timer and scroll-settle watcher on unmount so neither fires after the widget closes.
  useEffect(
    () => () => {
      if (flashTimerRef.current !== null) window.clearTimeout(flashTimerRef.current);
      if (scrollSettleRafRef.current !== null) window.cancelAnimationFrame(scrollSettleRafRef.current);
    },
    [],
  );

  // Retry the pending scroll after each message-list change (an older page merged in, or the live reply landed).
  useEffect(() => {
    if (pendingScrollRef.current) tryScrollToPendingMessage();
  }, [messages, tryScrollToPendingMessage]);

  return { followBottomRef, scrollJumpActiveRef };
};
