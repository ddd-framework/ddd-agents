import { MutableRefObject, RefObject, useCallback, useRef } from "react";

/**
 * The pin-question-on-send machinery of the AW-107 long-answer reading flow, extracted out of ConversationViewBody
 * so the addon lives in its own file (mirrors hooks/useIsMessagesBottomOutOfView.ts). On send, the user's
 * question is pinned to the top with empty room reserved below it and bottom-follow is suspended for the turn,
 * so the reply reveals from its beginning instead of chasing the bottom.
 *
 * Every method no-ops when `enabled` is false, so the disabled state can never leak a layout write
 * (padding-bottom) or a scroll call even if a caller forgets a gate.
 */
export const usePinQuestionOnSend = (options: {
  enabled: boolean;
  scrollContainer: HTMLDivElement | null;
  getMessagesListEl: () => HTMLElement | null;
  messagesBottomRef: RefObject<HTMLDivElement | null>;
  /** ConversationViewBody's followBottom ref — pinning suspends bottom-follow for the turn. */
  followBottomRef: MutableRefObject<boolean>;
}) => {
  const { enabled, scrollContainer, getMessagesListEl, messagesBottomRef, followBottomRef } = options;

  // While a turn's question is pinned at the top (from send until the next send / a manual scroll / a conversation
  // switch), we reserve empty room below it and stop chasing the bottom, so the reply flows in from its beginning.
  const questionPinnedThisTurn = useRef(false);

  const lastUserMessageRow = useCallback((): HTMLElement | null => {
    const list = getMessagesListEl();
    if (!list) return null;
    const rows = list.querySelectorAll<HTMLElement>('[data-message-role="USER"]');
    return rows[rows.length - 1] ?? null;
  }, [getMessagesListEl]);

  const userMessageRowCount = useCallback((): number => {
    const list = getMessagesListEl();
    return list ? list.querySelectorAll('[data-message-role="USER"]').length : 0;
  }, [getMessagesListEl]);

  // Reserve just enough empty room below the question that it can be scrolled fully to the top; the room shrinks to 0
  // once the reply alone fills the viewport. Pure layout write — no scrolling here.
  const reserveRoomBelowQuestion = useCallback(
    (questionRow: HTMLElement) => {
      const container = scrollContainer;
      const list = getMessagesListEl();
      const bottomMarker = messagesBottomRef.current;
      if (!container || !list || !bottomMarker) return;
      const topClearance = parseFloat(getComputedStyle(questionRow).scrollMarginTop) || 0;
      const realBelow = bottomMarker.offsetTop - questionRow.offsetTop; // question + reply/thinking so far
      const reserved = Math.max(0, container.clientHeight - topClearance - realBelow);
      list.style.paddingBottom = `${reserved}px`;
    },
    [scrollContainer, getMessagesListEl, messagesBottomRef],
  );

  // Pin the latest question row to the top and hand the space below to the reply. Returns false (no-op) when the
  // addon is disabled or there is no USER row to pin.
  const pinLatestQuestionToTop = useCallback(
    (behavior: ScrollBehavior): boolean => {
      if (!enabled) return false;
      const questionRow = lastUserMessageRow();
      if (!questionRow) return false;
      reserveRoomBelowQuestion(questionRow); // reserve first, so there's room to lift the question all the way up
      questionRow.scrollIntoView({ behavior, block: "start", inline: "nearest" });
      followBottomRef.current = false;
      questionPinnedThisTurn.current = true;
      return true;
    },
    [enabled, lastUserMessageRow, reserveRoomBelowQuestion, followBottomRef],
  );

  // Re-reserve room below the pinned question; no-op when disabled or not pinned. Used by the positioning layout
  // effect and the ResizeObserver as the reply grows.
  const reserveRoomBelowPinnedQuestion = useCallback(() => {
    if (!enabled || !questionPinnedThisTurn.current) return;
    const questionRow = lastUserMessageRow();
    if (questionRow) reserveRoomBelowQuestion(questionRow);
  }, [enabled, lastUserMessageRow, reserveRoomBelowQuestion]);

  const isQuestionPinnedThisTurn = useCallback(() => questionPinnedThisTurn.current, []);

  // Clear the pin + remove the reserved padding-bottom (conversation switch, scroll-to-latest).
  const endPinnedTurn = useCallback(() => {
    if (!enabled) return;
    questionPinnedThisTurn.current = false;
    getMessagesListEl()?.style.removeProperty("padding-bottom");
  }, [enabled, getMessagesListEl]);

  return {
    pinLatestQuestionToTop,
    reserveRoomBelowPinnedQuestion,
    isQuestionPinnedThisTurn,
    endPinnedTurn,
    userMessageRowCount,
  };
};
