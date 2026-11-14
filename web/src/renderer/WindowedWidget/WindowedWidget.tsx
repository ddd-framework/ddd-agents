import { InteractiveSurface, Text } from "../../provider/components";
import React, { useEffect, useRef } from "react";
import { translateText } from "../../provider/environment";
import { useWidgetWindow } from "../widget-windowing";
import styles from "../HiddenWidgetPlaceholder/HiddenWidgetPlaceholder.module.scss";

export interface WindowedWidgetProps {
  children: React.ReactNode;
  /** First-paint guess from the index window: recent messages start mounted, older ones start as a placeholder. */
  initialRevealed: boolean;
  /** The freshly-delivered widget — kept mounted (never windowed out) so its one-shot effects don't re-fire. */
  pinned: boolean;
}

/** Default reserved height for a widget that has never been measured (an old one that has not been scrolled to yet). */
const DEFAULT_PLACEHOLDER_MIN_HEIGHT = 80;

/**
 * Mounts a chat widget only while it is near the viewport (see {@link useWidgetWindow}); far from the viewport it
 * collapses to a lightweight placeholder. The wrapper element is STABLE across the toggle so the shared
 * IntersectionObserver keeps tracking it. The placeholder reserves the widget's last measured height, so re-hiding it
 * causes ZERO layout shift (no scroll jump); revealing again restores the real widget (self-hydrating widgets re-fetch
 * with a brief skeleton).
 */
export const WindowedWidget = ({ children, initialRevealed, pinned }: WindowedWidgetProps) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const [revealed, reveal] = useWidgetWindow(wrapperRef, { initialRevealed, pinned });
  // Remembered so the placeholder can reserve exactly the height the widget occupied.
  const lastHeightRef = useRef<number | undefined>(undefined);

  useEffect(() => {
    const element = wrapperRef.current;
    if (!revealed || !element || typeof ResizeObserver === "undefined") return undefined;
    const measure = () => {
      if (wrapperRef.current) lastHeightRef.current = wrapperRef.current.offsetHeight;
    };
    measure();
    const resizeObserver = new ResizeObserver(measure);
    resizeObserver.observe(element);
    return () => resizeObserver.disconnect();
  }, [revealed]);

  return (
    <div ref={wrapperRef}>
      {revealed ? (
        children
      ) : (
        <InteractiveSurface
          hoverable
          className={styles.placeholder}
          style={{ minHeight: lastHeightRef.current ?? DEFAULT_PLACEHOLDER_MIN_HEIGHT }}
        >
          <button
            type="button"
            className={styles.placeholderButton}
            onClick={reveal}
            aria-label={translateText("Show hidden widget")}
          >
            <Text>Older interactive content is hidden.</Text>
            <span className={styles.cta}>
              <Text>Show</Text>
            </span>
          </button>
        </InteractiveSurface>
      )}
    </div>
  );
};
