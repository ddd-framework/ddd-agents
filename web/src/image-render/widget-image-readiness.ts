import { publishWidgetImageReadiness, readWidgetImageReadiness } from "./widget-image-readiness-signal";
import type { WidgetImageRenderBootstrap, WidgetImageReadiness } from "./widget-image-render.types";

const MAX_TIMER_DELAY_MS = 2 ** 31 - 1;

export const createWidgetImageReadiness = (bootstrap: WidgetImageRenderBootstrap) => {
  let failed = readWidgetImageReadiness()?.state === "failed";
  let requestsPending = 0;
  let hydrationPending = false;
  let notifyChanged = () => {};
  let observed: HTMLElement | null = null;
  const identity = {
    version: 1 as const,
    snapshot_id: bootstrap.snapshotId,
    attempt_id: bootstrap.attemptId,
    input_sha256: bootstrap.inputSha256,
    render_version: bootstrap.renderVersion,
  };
  const publish = (state: WidgetImageReadiness["state"], extra: Partial<WidgetImageReadiness> = {}) => {
    publishWidgetImageReadiness({ ...identity, state, ...extra });
    // Mirror outside the observed widget subtree for browser checks without changing screenshot pixels.
    document.documentElement.dataset.widgetImageState = state;
    document.documentElement.dataset.widgetImageError = extra.error_code ?? "";
  };
  if (!failed) publish("loading");
  const measure = (element: HTMLElement) => {
    const { x, y, width, height } = element.getBoundingClientRect();
    return { x, y, width, height };
  };
  // A failed state still reports the widget box so the renderer can capture it when the caller suppresses errors.
  const fail = (code: string) => {
    if (failed || readWidgetImageReadiness()?.state === "failed") return;
    failed = true;
    const rect = observed ? measure(observed) : undefined;
    publish("failed", rect && rect.width > 0 && rect.height > 0 ? { error_code: code, rect } : { error_code: code });
  };
  const isFailed = () => failed || readWidgetImageReadiness()?.state === "failed";
  const frame = () => new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));

  const ready = async (element: HTMLElement, isCurrent: () => boolean = () => true) => {
    observed = element;
    try {
      // Effects and their state updates must commit before inspecting the final content.
      await frame();
      await frame();
      await document.fonts.ready;
      document.fonts.forEach((font) => {
        if (font.status === "error") throw new Error("FONT_FAILED");
      });
      const images = Array.from(element.querySelectorAll("img"));
      await Promise.all(
        images.map(async (img) => {
          // Empty decorative image slots have nothing to download or decode.
          if (!img.currentSrc && !img.getAttribute("src") && !img.getAttribute("srcset") && img.alt === "") return;
          if (img.loading !== "eager") img.loading = "eager";
          await img.decode();
          if (!img.naturalWidth) throw new Error("ASSET_FAILED");
        }),
      );
      await frame();
      await frame();
      if (isFailed() || !isCurrent()) return;
      if (requestsPending > 0 || hydrationPending) return;
      if (Date.now() >= bootstrap.expiresAt * 1000) return fail("TOKEN_EXPIRED");
      // Determinate bars visualize scores too; only indeterminate bars imply loading.
      if (
        element.querySelector(
          '[aria-busy="true"], [role="progressbar"]:not([aria-valuenow]), [class*="Skeleton"], [class*="skeleton"]',
        )
      ) {
        if (bootstrap.input.mode === "live") return;
        return fail("WIDGET_STILL_LOADING");
      }
      const rect = element.getBoundingClientRect();
      const width = bootstrap.input.width ?? 528;
      const maxHeight = bootstrap.input.maxHeight ?? 1200;
      if (!element.textContent?.trim() && !element.querySelector("img,svg,canvas")) return fail("EMPTY_WIDGET");
      if (
        rect.width <= 0 ||
        rect.height <= 0 ||
        rect.width > width + 1 ||
        rect.height > maxHeight ||
        rect.x < 0 ||
        rect.y < 0 ||
        element.scrollWidth > Math.ceil(rect.width) ||
        element.scrollHeight > Math.ceil(rect.height)
      ) {
        return fail("WIDGET_BOUNDS_EXCEEDED");
      }
      // The capture viewport may be smaller than the widget; the renderer sizes its viewport/clip from this rect.
      publish("ready", { rect: measure(element) });
    } catch {
      if (isCurrent()) fail("REQUIRED_ASSET_FAILED");
    }
  };
  const observe = (element: HTMLElement) => {
    observed = element;
    let revision = 0;
    let timer: ReturnType<typeof setTimeout>;
    let disposed = false;
    const changed = () => {
      if (disposed || isFailed()) return;
      revision += 1;
      const expected = revision;
      publish("loading");
      clearTimeout(timer);
      // Frozen hydrators resolve synchronously; legacy frozen GET effects and chart layout may commit later.
      timer = setTimeout(() => void ready(element, () => !disposed && expected === revision), 250);
    };
    const observer = new MutationObserver(changed);
    notifyChanged = changed;
    observer.observe(element, { childList: true, subtree: true, attributes: true, characterData: true });
    const resize = new ResizeObserver(changed);
    resize.observe(element);
    changed();
    const remaining = bootstrap.expiresAt * 1000 - Date.now();
    // Browsers fire delays above 2^31-1 ms immediately; long-lived URLs cannot expire within the render deadline.
    const expiry =
      remaining <= MAX_TIMER_DELAY_MS ? setTimeout(() => fail("TOKEN_EXPIRED"), Math.max(0, remaining)) : undefined;
    const deadline = setTimeout(() => {
      if (readWidgetImageReadiness()?.state !== "ready") fail("RENDER_DEADLINE_EXCEEDED");
    }, 60000);
    return () => {
      disposed = true;
      observer.disconnect();
      resize.disconnect();
      clearTimeout(timer);
      if (expiry !== undefined) clearTimeout(expiry);
      clearTimeout(deadline);
      notifyChanged = () => {};
    };
  };
  const beginRequest = () => {
    requestsPending += 1;
    if (!isFailed()) publish("loading");
    notifyChanged();
    return () => {
      requestsPending -= 1;
      notifyChanged();
    };
  };
  const setHydrationPending = (pending: boolean) => {
    if (hydrationPending === pending) return;
    hydrationPending = pending;
    if (!isFailed()) publish("loading");
    notifyChanged();
  };
  return { ready, observe, fail, isFailed, beginRequest, setHydrationPending };
};
