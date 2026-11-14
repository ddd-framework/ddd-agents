import { publishWidgetImageReadiness } from "../image-render/widget-image-readiness-signal";
import type { WidgetRenderReportEntry } from "../renderer/render-report/widget-render-report";
import { publishWidgetPreviewSignal } from "./widget-preview-signal";
import type { WidgetPreviewExpectedState, WidgetPreviewSignal } from "./widget-preview.types";

/** A loading placeholder: the component library's `Skeleton` (its test id; its class is a hashed module name) and the kit's own. */
const SKELETON_SELECTOR = '[data-testid="Skeleton"], [class*="Skeleton"], [class*="skeleton"]';
const LOADING_SELECTOR = `[aria-busy="true"], [role="progressbar"]:not([aria-valuenow]), ${SKELETON_SELECTOR}`;
/** The DOM must stay unchanged this long before the preview counts as settled. */
const SETTLE_MS = 400;

/**
 * Whether a loading marker is actually rendered: the component library's `ImgWithLoader` keeps its Skeleton in the DOM inside a
 * `display: none` wrapper once the image has loaded, so a marker under a hidden ancestor is no loading state. Without
 * a layout engine (jsdom: no client rects for any element) every marker counts as before.
 */
const hasRenderedLoadingMarker = (element: HTMLElement): boolean => {
  const markers = Array.from(element.querySelectorAll(LOADING_SELECTOR));
  if (markers.length === 0) return false;
  const hasLayout = element.getClientRects().length > 0;
  return !hasLayout || markers.some((marker) => marker.getClientRects().length > 0);
};

/**
 * The state an element shows — the same loading markers the e-mail capture refuses to shoot. `hasError` is true when
 * the widget is known to be in an error: a failed hydration, a crash caught by the error boundary, or a request the
 * fixture failed on purpose — a widget that renders nothing on an error would otherwise read as `empty`.
 * `renderReport` is the block's own render report when the widget sent one: `failed` reads as `error`,
 * `pending` as `loading` and `empty` as `empty` — a widget that shows one muted line ("No data to show.") for its empty
 * state is empty, whatever text the line holds.
 */
export const observeWidgetPreviewState = (
  element: HTMLElement,
  hasError: boolean,
  renderReport?: WidgetRenderReportEntry,
): WidgetPreviewExpectedState => {
  if (hasError || renderReport?.status === "failed") return "error";
  if (renderReport?.status === "pending") return "loading";
  if (hasRenderedLoadingMarker(element)) return "loading";
  if (renderReport?.status === "empty") return "empty";
  if (!element.textContent?.trim() && !element.querySelector("img,svg,canvas,iframe")) return "empty";
  return "data";
};

/**
 * A mutation the settle ignores: a style or attribute change inside a loading placeholder — the component library's `Skeleton`
 * animates its shimmer by rewriting its style every frame, so a loading fixture would never be still otherwise.
 */
const isSkeletonAnimation = (mutation: MutationRecord): boolean => {
  if (mutation.type !== "attributes") return false;
  const target = mutation.target;
  return target instanceof Element && target.closest(SKELETON_SELECTOR) !== null;
};

/**
 * The preview's ready signal: after every change it waits until the DOM is still for `SETTLE_MS`, then publishes what
 * it shows — `window.__AGENTS_WIDGET_PREVIEW_V1__` (observed against the fixture's expected state, with every request
 * the fixture had no response for) and the e-mail capture's `window.__AGENTS_WIDGET_IMAGE_V1__` (`ready` when the
 * expected state was reached), so the same script conventions work for both. Both are also published under the names
 * the app declares (`AgentsEnvironment.sandboxLegacyAliases`).
 */
export const createWidgetPreviewReadiness = (expectedState: WidgetPreviewExpectedState) => {
  const missingResponses: string[] = [];
  const failedRequests: string[] = [];
  let widgetCrashed = false;
  let renderReport: WidgetRenderReportEntry | undefined;
  let scheduleSettleAgain: () => void = () => {};
  let revision = 0;
  const publish = (
    signal: Omit<
      WidgetPreviewSignal,
      "version" | "missingResponses" | "failedRequests" | "widgetCrashed" | "expectedState"
    >,
  ) => {
    publishWidgetPreviewSignal({
      version: 1,
      expectedState,
      missingResponses: [...missingResponses],
      failedRequests: [...failedRequests],
      widgetCrashed,
      ...(renderReport ? { renderReport } : {}),
      ...signal,
    });
    document.documentElement.dataset.widgetPreviewState = signal.state;
  };
  publish({ state: "loading", revision });

  const fail = (errorCode: string) => {
    publish({ state: "failed", revision, error_code: errorCode });
    publishWidgetImageReadiness({ version: 1, state: "failed", error_code: errorCode });
  };

  const recordMissingResponse = (requestKey: string) => {
    if (!missingResponses.includes(requestKey)) missingResponses.push(requestKey);
  };

  /** A request (or a preview hydration) the fixture answered with a failing `__status`. */
  const recordFailedRequest = (requestKey: string) => {
    if (!failedRequests.includes(requestKey)) failedRequests.push(requestKey);
  };

  /** The error boundary caught the widget (`ConversationWidgetErrorBoundary` `onCrash`). */
  const recordWidgetCrash = () => {
    widgetCrashed = true;
  };

  /** The block's render report (`WidgetRenderReportContext` of the host); a new status settles again. */
  const recordRenderReport = (entry: WidgetRenderReportEntry) => {
    renderReport = entry;
    scheduleSettleAgain();
  };

  const settle = (element: HTMLElement, hasHydrationError: () => boolean) => {
    const hasError = hasHydrationError() || widgetCrashed || failedRequests.length > 0;
    const observedState = observeWidgetPreviewState(element, hasError, renderReport);
    const { x, y, width, height } = element.getBoundingClientRect();
    const rect = { x, y, width, height };
    revision += 1;
    const expectedStateReached = observedState === expectedState;
    publish({ state: "settled", revision, observedState, expectedStateReached, rect });
    publishWidgetImageReadiness(
      expectedStateReached
        ? { version: 1, state: "ready", rect }
        : {
            version: 1,
            state: "failed",
            error_code: `EXPECTED_${expectedState.toUpperCase()}_SAW_${observedState.toUpperCase()}`,
            rect,
          },
    );
  };

  /**
   * Watches `watchedElement` (the whole preview document, so an interaction that changes only the canvas pane or opens
   * a modal settles too)
   * and classifies `widgetElement` (the widget alone, the screenshot); returns the disposer.
   */
  const observe = (watchedElement: HTMLElement, widgetElement: HTMLElement, hasHydrationError: () => boolean) => {
    let settleTimer: ReturnType<typeof setTimeout> | null = null;
    const scheduleSettle = () => {
      if (settleTimer !== null) clearTimeout(settleTimer);
      settleTimer = setTimeout(() => settle(widgetElement, hasHydrationError), SETTLE_MS);
    };
    const mutationObserver = new MutationObserver((mutations) => {
      if (mutations.some((mutation) => !isSkeletonAnimation(mutation))) scheduleSettle();
    });
    mutationObserver.observe(watchedElement, {
      subtree: true,
      childList: true,
      attributes: true,
      characterData: true,
    });
    scheduleSettleAgain = scheduleSettle;
    scheduleSettle();
    return () => {
      mutationObserver.disconnect();
      scheduleSettleAgain = () => {};
      if (settleTimer !== null) clearTimeout(settleTimer);
    };
  };

  return { observe, fail, recordMissingResponse, recordFailedRequest, recordWidgetCrash, recordRenderReport };
};

export type WidgetPreviewReadiness = ReturnType<typeof createWidgetPreviewReadiness>;
