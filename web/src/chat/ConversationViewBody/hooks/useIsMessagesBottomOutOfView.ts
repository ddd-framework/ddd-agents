import { RefObject, useEffect, useState } from "react";

/** The button appears once the messagesBottom sentinel is this far below the visible bottom edge. */
const SHOW_BUTTON_BELOW_FOLD_PX = 300;

export const useIsMessagesBottomOutOfView = (
  scrollContainer: HTMLDivElement | null,
  messagesList: HTMLDivElement | null,
  messagesBottomRef: RefObject<HTMLDivElement | null>,
): boolean => {
  const [isOutOfView, setIsOutOfView] = useState(false);

  useEffect(() => {
    const messagesBottomSentinel = messagesBottomRef.current;
    if (!scrollContainer || !messagesBottomSentinel) {
      setIsOutOfView(false);
      return undefined;
    }
    const observer = new IntersectionObserver(
      (entries) => setIsOutOfView(!entries[entries.length - 1].isIntersecting),
      // Extends the root's bottom edge by 300px: the sentinel counts as "in view" until it is
      // more than 300px below the fold, so the button only appears after real scrolling up.
      { root: scrollContainer, rootMargin: `0px 0px ${SHOW_BUTTON_BELOW_FOLD_PX}px 0px` },
    );
    observer.observe(messagesBottomSentinel);
    return () => {
      observer.disconnect();
      setIsOutOfView(false);
    };
  }, [scrollContainer, messagesList, messagesBottomRef]);

  return isOutOfView;
};
