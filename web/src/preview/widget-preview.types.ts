import type { WidgetRenderReportEntry } from "../renderer/render-report/widget-render-report";

/**
 * The widget preview: one registered widget — or a `ComposedView` tree — rendered outside the chat from
 * a named fixture, in one of three modes, so a script can screenshot it, drive its interactions and compare it with the
 * ChatGPT reference. The fixtures live next to each widget (`<kebab-name>.preview-fixtures.json`); the same dummy data
 * can feed the widget's stories.
 */

/** `interactive`: the live reply (inputs work, canvases open); `replay`: a reloaded message; `email`: the capture look. */
export type WidgetPreviewMode = "interactive" | "replay" | "email";

/** What the fixture is built to show once it settles; the preview reports whether that state was reached. */
export type WidgetPreviewExpectedState = "data" | "loading" | "empty" | "error";

/** One step the compare script performs after the first screenshot (then it screenshots again). */
export interface WidgetPreviewInteraction {
  name: string;
  /** Click the element with this visible text, or matching `selector`. */
  click?: { text?: string; selector?: string };
  /** Type `value` into the element matching `selector`. */
  fill?: { selector: string; value: string };
  /** Press one key (`Enter`, `ArrowRight`, …) on the focused element. */
  press?: string;
  /**
   * Hover the element with this visible text, or matching `selector` — at `position` (CSS px from its top left corner)
   * when given; with `position` alone the widget itself is hovered there (a chart point, a map marker).
   */
  hover?: { text?: string; selector?: string; position?: { x: number; y: number } };
  /**
   * The state this step must settle in, when it differs from the fixture's — the click that opens a detail whose read
   * fails on purpose expects `error` while the chat block before it shows `data` (`scripts/widget-preview/preview-step-state.mjs`).
   */
  expectedState?: WidgetPreviewExpectedState;
}

/** A frozen GET response: the body, or a status the request fails with, or a request that never answers (loading). */
export type WidgetPreviewResponse = unknown | { __status: number; __body?: unknown } | { __pending: true };

export interface WidgetPreviewFixture {
  name: string;
  description?: string;
  /**
   * The shared test data set the fixture's data comes from (e.g. `kassel-dental-practice`, one coherent customer across
   * every domain) — read by the widget comparison manifest; the preview ignores it.
   */
  dataSet?: string;
  /** The fence fields next to `type`. */
  payload: Record<string, unknown>;
  // A fixture describes a block, so the key of its agent space sits on the fixture under the block field the app
  // declares (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`) and is read with `readWidgetAgentSpaceKeyAttribute`.
  /**
   * Hydrator type (or exact derived cache key) → the resolved result, as the e-mail capture takes it. In `email` mode it
   * is the capture's frozen hydration; in `interactive` / `replay` the preview's hydrators answer with it (a value may
   * be a `__status` or `__pending` entry like a response), so the widget runs its live code path — a self-hydrating
   * canvas widget auto-opens its canvas. A hydrator type it does not name runs for real against `responses`.
   */
  hydration?: Record<string, WidgetPreviewResponse>;
  /**
   * `"GET /path?sorted=query"` → the response; a request with no entry fails as a missing response. A `__status`
   * entry of 400 or more fails the request and makes the preview observe `error`.
   */
  responses?: Record<string, WidgetPreviewResponse>;
  /** Location / project settings for the store, as the e-mail capture takes them. */
  settings?: Record<string, unknown>;
  /**
   * The owner's language (`de`, `en`, `fr`, or a full `de-DE`) — the store's `GlobalReducer` language, so numbers and
   * dates format as for that owner; the location's date locale follows unless `settings.location.localeFormatted` is
   * set. Default `de`.
   */
  locale?: string;
  expectedState?: WidgetPreviewExpectedState;
  /** The chat width the screenshot is taken at; the script adds a narrow one. */
  width?: number;
  interactions?: WidgetPreviewInteraction[];
  /** Render the kit's node types that have no component yet with the preview's stub components. */
  stubComposedViewComponents?: boolean;
  /**
   * Parts of the URLs the browser fails to load on purpose (the picture of a broken-image fixture) — the render sweep
   * (`scripts/widget-preview/render-sweep.mjs`) reports them as FAILED_ON_PURPOSE instead of REQUEST_FAILED.
   */
  expectedFailedRequests?: string[];
}

export interface WidgetPreviewFixtureFile {
  wireName: string;
  fixtures: WidgetPreviewFixture[];
}

export interface WidgetPreviewBootstrap {
  widgetName: string;
  fixtureName: string;
  mode: WidgetPreviewMode;
  width?: number;
}

/** What the page publishes for a script, beside the e-mail capture's `__AGENTS_WIDGET_IMAGE_V1__`. */
export interface WidgetPreviewSignal {
  version: 1;
  state: "loading" | "settled" | "failed";
  /** Bumped on every settle — after an interaction the script waits for the next revision. */
  revision: number;
  expectedState: WidgetPreviewExpectedState;
  observedState?: WidgetPreviewExpectedState;
  expectedStateReached?: boolean;
  /** Requests the fixture has no response for (a harness gap, not a widget defect). */
  missingResponses: string[];
  /** Requests (and preview hydrations) the fixture answered with a failing `__status` — the error state it asked for. */
  failedRequests: string[];
  /** The widget threw and `ConversationWidgetErrorBoundary` caught it. */
  widgetCrashed: boolean;
  /** The block's last render report, when the widget sent one — the observed state follows it. */
  renderReport?: WidgetRenderReportEntry;
  error_code?: string;
  rect?: { x: number; y: number; width: number; height: number };
}

declare global {
  interface Window {
    /** The preview's readiness signal (`publishWidgetPreviewSignal` also writes it under the app's declared names). */
    __AGENTS_WIDGET_PREVIEW_V1__?: WidgetPreviewSignal;
  }
}
