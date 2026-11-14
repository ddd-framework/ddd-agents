import { RefObject, useContext, useLayoutEffect } from "react";
import { WidgetWindowRootContext } from "../../../../renderer/widget-windowing";

/**
 * Fade a freshly-delivered assistant reply's rendered block elements in, one after another, instead of
 * popping the whole wall of text in at once. The reveal operates on the DOM blocks the markdown renderer
 * already produces (the direct children of the `[data-markdown-blocks-root]` div that is containerRef's
 * DIRECT child — nested Markdown instances rendered by widgets stamp the same attribute deeper
 * down and must not be picked up) — the markdown string is never split, so code fences, tables, nested
 * lists, and widgets are handled uniformly.
 *
 * - Blocks in view on arrival (tested against the FULL viewport, edge to edge): a quick staggered fade-in
 *   (STAGGER_STEP_MS apart, capped at STAGGER_CAP_MS; each block fades over the faster INITIAL_FADE_MS set
 *   inline) — the visible screen fills with text down to the bottom edge fast, no hidden band at the bottom.
 * - Blocks below the fold: a single, calmer fade at the CSS default duration the FIRST time any pixel of
 *   them enters the scroll root — never replayed, never reversed on scroll-up.
 * - VISIBILITY DEADLINE: whatever the observers did or did not see, every block still hidden
 *   VISIBILITY_DEADLINE_MS after the reply arrived becomes plainly visible (no fade) and the observers are
 *   dropped. Delivered text must never depend on a scroll event to become readable.
 *
 * Invariants:
 * - Opacity + transform only, never display/visibility/height — hidden blocks keep their final layout space
 *   from the first frame, so the pin/reserve machinery in ConversationViewBody sees stable heights and Cmd+F /
 *   selection / a11y still find the pending text.
 * - Only fresh static replies animate (the caller gates via `enabled`); streaming is excluded upstream.
 * - Once per message id: a re-render / windowing pass / navigate-away-and-back never replays it.
 * - A block can never be stuck hidden: hiding happens in the same synchronous useLayoutEffect that creates
 *   the observer; the deadline and the cleanup both finalize every still-hidden block to visible. (A
 *   "-15% into the viewport" trigger left the reply's closing question invisible while the pinned-question
 *   policy never scrolled — the owner saw the question only after their next send. Hence zero margin AND the
 *   deadline.)
 */

const STAGGER_STEP_MS = 90;
const STAGGER_CAP_MS = 720;
// The initial screen-fill batch fades each block faster than the CSS default (900ms) so the first screen
// populates snappily; blocks scrolled into view later keep the calmer default fade untouched.
const INITIAL_FADE_MS = 550;
// Scroll-reveal trigger — below-the-fold blocks ONLY (the initial batch tests against the full viewport).
// Zero margin: a block reveals the moment any pixel of it is inside the scroll root. (A negative bottom
// margin — "reveal once the top is 15% up into the viewport" — left a block whose top sat in that bottom band
// hidden for good when nothing scrolled; a percentage margin is even measured against the root's WIDTH.)
const SCROLL_REVEAL_ROOT_MARGIN = "0px";
// Hard upper bound on hiding: after this, every still-hidden block is made visible without animation.
export const VISIBILITY_DEADLINE_MS = 2500;

// Module-scoped so the guard survives unmount/remount of ConversationMessageWidgets (Redux re-render, widget
// windowing, navigate-away-and-back within the session) — the same one-shot pattern as
// reloadReopenMessageIdRef in ConversationMessageList.
const alreadyRevealedMessageIds = new Set<string | number>();

export const useFreshMessageBlockReveal = (options: {
  /**
   * The rendered-message container — ConversationMessageWidgets's existing renderedRef. Its DIRECT child must be
   * the reply's own `[data-markdown-blocks-root]` div (ConversationMarkdown renders exactly one Markdown,
   * whose context provider adds no DOM node); the hook queries only that direct child.
   */
  containerRef: RefObject<HTMLDivElement | null>;
  /** fresh && message != null — static fresh assistant replies only. */
  enabled: boolean;
  /** Once-only guard key; when null/undefined the reply animates but is not recorded. */
  messageId?: string | number | null;
}): void => {
  const { containerRef, enabled, messageId } = options;
  const scrollRoot = useContext(WidgetWindowRootContext);

  useLayoutEffect(() => {
    if (!enabled) return;
    if (messageId != null && alreadyRevealedMessageIds.has(messageId)) return;
    if (typeof IntersectionObserver === "undefined") return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    // Markdown rendered outside the chat scroll container (drawer/canvas hosts render their own Markdown
    // instances) has no scroll root and must never inherit the reveal.
    if (scrollRoot == null) return;

    // Direct child ONLY (`:scope >`): the shared Markdown component stamps `data-markdown-blocks-root` on
    // every instance, and a widget inside the reply can render its own nested Markdown (e.g.
    // WebSearchAnswer). The reply's own blocks root is always renderedRef's direct child — ConversationMarkdown
    // renders a context provider (no DOM node) around exactly one Markdown — so a descendant query could
    // conflate roots, while the direct-child query can only match the outer one.
    const blocksRoot = containerRef.current?.querySelector(":scope > [data-markdown-blocks-root]");
    if (blocksRoot == null) return;

    const blocks = Array.from(blocksRoot.children) as HTMLElement[];
    if (blocks.length === 0) return;

    for (const block of blocks) block.dataset.blockReveal = "hidden";
    if (messageId != null) alreadyRevealedMessageIds.add(messageId);

    // The one idempotent end state: observers gone, every still-hidden block plainly visible (the hook's
    // attribute and inline overrides removed — assigning "revealed" would start another 900ms fade), blocks
    // already revealed keep their finished state. Reached by the deadline, by cleanup, or by an observer
    // setup failure; queued observer callbacks after it are no-ops.
    let finalized = false;
    let scrollRevealObserver: IntersectionObserver | null = null;
    let initialViewportObserver: IntersectionObserver | null = null;
    const finalize = () => {
      if (finalized) return;
      finalized = true;
      initialViewportObserver?.disconnect();
      scrollRevealObserver?.disconnect();
      for (const block of blocks) {
        if (block.dataset.blockReveal === "hidden") {
          delete block.dataset.blockReveal;
          block.style.animationDelay = "";
          block.style.animationDuration = "";
        }
      }
    };
    const deadline = window.setTimeout(finalize, VISIBILITY_DEADLINE_MS);

    // Below-the-fold blocks reveal the first time any pixel of them enters the scroll root — single fade, no delay.
    scrollRevealObserver = new IntersectionObserver(
      (entries, observer) => {
        if (finalized) return;
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          (entry.target as HTMLElement).dataset.blockReveal = "revealed";
          observer.unobserve(entry.target);
        }
      },
      { root: scrollRoot, rootMargin: SCROLL_REVEAL_ROOT_MARGIN, threshold: 0 },
    );

    // The initial pass fires exactly once, right after observe(), with every block's intersection state
    // against the FULL viewport (no trigger margin): everything visible — under the pinned question, down
    // to the bottom edge — reveals staggered, so the first screen is completely filled with text. The
    // blocks below the fold are handed to scrollRevealObserver.
    initialViewportObserver = new IntersectionObserver(
      (entries, observer) => {
        if (finalized) return;
        let staggerIndex = 0;
        for (const entry of entries) {
          const block = entry.target as HTMLElement;
          if (entry.isIntersecting) {
            block.style.animationDelay = `${Math.min(staggerIndex * STAGGER_STEP_MS, STAGGER_CAP_MS)}ms`;
            block.style.animationDuration = `${INITIAL_FADE_MS}ms`;
            staggerIndex++;
            block.dataset.blockReveal = "revealed";
          } else {
            scrollRevealObserver?.observe(block);
          }
        }
        observer.disconnect();
      },
      { root: scrollRoot, threshold: 0 },
    );

    for (const block of blocks) initialViewportObserver.observe(block);

    return () => {
      window.clearTimeout(deadline);
      finalize();
    };
  }, [enabled, messageId, scrollRoot, containerRef]);
};
