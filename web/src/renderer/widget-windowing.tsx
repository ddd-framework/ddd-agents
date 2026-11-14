import React, { createContext, RefObject, useContext, useEffect, useState } from "react";

/**
 * Viewport-windowing for chat widgets. Heavy interactive widgets (maps, lists, hydrating components) are mounted ONLY
 * while near the viewport and unmounted back to a placeholder when far away — so a long conversation keeps a CONSTANT
 * number of widgets mounted no matter how far you scroll. Two thresholds give hysteresis (reveal early, hide late) so a
 * small scroll wobble never thrashes mount/unmount.
 *
 * The IntersectionObserver root is the chat scroll container, supplied via {@link WidgetWindowRootContext} by the chat
 * host (ConversationViewBody). Observers are POOLED per (root, margin): all placeholders share two observers, not two each.
 */
export const WidgetWindowRootContext = createContext<HTMLElement | null>(null);

/** Mount when the widget is within this margin of the viewport. */
const REVEAL_MARGIN_PX = 600;
/** Unmount only once it is beyond this (larger) margin — the gap to REVEAL is the hysteresis band. */
const HIDE_MARGIN_PX = 2000;

type Pool = { observer: IntersectionObserver; callbacks: Map<Element, (entry: IntersectionObserverEntry) => void> };
// Keyed by root (null = viewport) → margin string → the shared observer + its per-element callbacks.
const poolsByRoot = new Map<Element | null, Map<string, Pool>>();

/** Register `element` with the shared observer for (root, rootMargin); returns a disposer. */
const observeShared = (
  root: Element | null,
  rootMargin: string,
  element: Element,
  onEntry: (entry: IntersectionObserverEntry) => void,
): (() => void) => {
  let byMargin = poolsByRoot.get(root);
  if (!byMargin) {
    byMargin = new Map();
    poolsByRoot.set(root, byMargin);
  }
  let pool = byMargin.get(rootMargin);
  if (!pool) {
    const callbacks = new Map<Element, (entry: IntersectionObserverEntry) => void>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          callbacks.get(entry.target)?.(entry);
        }
      },
      { root, rootMargin },
    );
    pool = { observer, callbacks };
    byMargin.set(rootMargin, pool);
  }
  pool.callbacks.set(element, onEntry);
  pool.observer.observe(element);

  return () => {
    const currentByMargin = poolsByRoot.get(root);
    const currentPool = currentByMargin?.get(rootMargin);
    if (!currentPool) return;
    currentPool.observer.unobserve(element);
    currentPool.callbacks.delete(element);
    if (currentPool.callbacks.size === 0) {
      currentPool.observer.disconnect();
      currentByMargin!.delete(rootMargin);
      if (currentByMargin!.size === 0) poolsByRoot.delete(root);
    }
  };
};

/**
 * Drives a widget's mounted/unmounted state from its viewport proximity.
 *
 * - `pinned` (the freshly-delivered widget) → always revealed, never observed: a live widget must not unmount while its
 *   one-shot effects (canvas auto-open) are in play, and re-mounting it later would re-fire them.
 * - otherwise: starts at `initialRevealed` (the cheap index-window guess for first paint), then the reveal observer
 *   mounts it when it scrolls within REVEAL_MARGIN and the hide observer unmounts it past HIDE_MARGIN.
 *
 * Returns `[revealed, reveal]` — `reveal` lets the placeholder force a manual mount (the "Show" affordance).
 */
export const useWidgetWindow = (
  targetRef: RefObject<HTMLElement | null>,
  options: { initialRevealed: boolean; pinned: boolean },
): [boolean, () => void] => {
  const { initialRevealed, pinned } = options;
  const root = useContext(WidgetWindowRootContext);
  const [revealed, setRevealed] = useState(initialRevealed || pinned);

  useEffect(() => {
    if (pinned) {
      setRevealed(true);
      return undefined;
    }
    const element = targetRef.current;
    if (!element || typeof IntersectionObserver === "undefined") return undefined;

    const disposeReveal = observeShared(root, `${REVEAL_MARGIN_PX}px 0px`, element, (entry) => {
      if (entry.isIntersecting) setRevealed(true);
    });
    const disposeHide = observeShared(root, `${HIDE_MARGIN_PX}px 0px`, element, (entry) => {
      if (!entry.isIntersecting) setRevealed(false);
    });
    return () => {
      disposeReveal();
      disposeHide();
    };
    // Re-attach when the scroll root resolves (it is null on the first frame, then the container element).
  }, [root, pinned, targetRef]);

  return [revealed, () => setRevealed(true)];
};
