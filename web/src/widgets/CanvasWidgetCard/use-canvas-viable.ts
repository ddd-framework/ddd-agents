import { useEffect, useState } from "react";

/**
 * Min viewport width (px) at which the canvas side-panel split is usable. Mirrors the ModalSplitView `autoCloseWidth`
 * in ConversationView — below it the split auto-collapses, so a canvas widget must render its content inline instead of opening
 * a panel that immediately closes.
 */
export const CANVAS_VIABLE_MIN_WIDTH = 850;

const isViable = () => window.innerWidth >= CANVAS_VIABLE_MIN_WIDTH;

/**
 * True when the viewport is wide enough for the canvas side panel. Re-renders the consumer ONLY when the boolean
 * crosses {@link CANVAS_VIABLE_MIN_WIDTH} — NOT on every resize pixel. This matters because the consumers (the canvas
 * widgets, ConversationView) re-mount heavy, self-fetching lists; a width-tracking
 * hook would re-render them on every resize tick and make dragging the window janky. We store the derived boolean and
 * skip the state update when it hasn't flipped, so React bails out and a drag that stays on one side of the threshold
 * costs zero re-renders.
 */
export const useCanvasViable = (): boolean => {
  const [viable, setViable] = useState(isViable);

  useEffect(() => {
    const onResize = () =>
      setViable((previous) => {
        const next = isViable();
        return next === previous ? previous : next;
      });
    window.addEventListener("resize", onResize);
    // Sync once in case the width changed between the initial render and this effect running.
    onResize();
    return () => window.removeEventListener("resize", onResize);
  }, []);

  return viable;
};
