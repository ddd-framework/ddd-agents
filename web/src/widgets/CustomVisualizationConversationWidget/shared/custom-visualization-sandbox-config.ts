import bundledFrameUrl from "../sandbox-frame/custom-visualization-frame.html?url";

/**
 * Where the sandbox frame of a CustomVisualization is served from — committed configuration, no
 * environment switch.
 *
 * `frameUrl: null` (the default) uses the frame page our own build emits (`app-build/assets/custom-visualization-frame-
 * <hash>.html`, the Vite dev server's copy locally): the app's own origin. The frame is still sandboxed
 * (`sandbox="allow-scripts"`, an opaque origin: no cookies, no storage, no access to the chat's DOM), and Chromium puts
 * a sandboxed frame into a process of its own; a browser that does not (Safari, a Chromium without site isolation) runs
 * it in the chat's process, so an endless loop in the model's script can freeze the chat before the watchdog removes
 * the frame.
 *
 * Set `frameUrl` to the absolute URL of the same `custom-visualization-frame.html` on a host of ANOTHER registrable
 * domain (a separate site — a subdomain of the app's domain is the same site) to have every browser with site isolation
 * run it out of process. The file is self-contained (no relative references); the libraries travel in the render
 * message, so that host serves this one static file and nothing else.
 */
export const CUSTOM_VISUALIZATION_SANDBOX_CONFIG: { frameUrl: string | null } = {
  frameUrl: null,
};

/** The frame URL the widget loads. */
export const resolveCustomVisualizationFrameUrl = (): string =>
  CUSTOM_VISUALIZATION_SANDBOX_CONFIG.frameUrl ?? bundledFrameUrl;
