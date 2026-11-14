// `@mgamadeus/ddd-agents/preview` — the widget preview host (one widget from one fixture, outside the chat), its fixture
// format and readiness, and the request and hydration stand-ins it installs.
export { WidgetPreview, PREVIEW_AGENT_SPACE_KEY, type WidgetPreviewProps } from "../preview/WidgetPreviewHost";
export * from "../preview/widget-preview.types";
export {
  buildWidgetPreviewFence,
  findWidgetPreviewFixture,
  findWidgetPreviewFixtures,
  registerWidgetPreviewFixtureFiles,
  widgetPreviewFixtureFiles,
} from "../preview/widget-preview-fixtures";
export {
  createWidgetPreviewReadiness,
  observeWidgetPreviewState,
  type WidgetPreviewReadiness,
} from "../preview/widget-preview-readiness";
export {
  publishWidgetPreviewSignal,
  readWidgetPreviewSignalGlobalNames,
  WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME,
} from "../preview/widget-preview-signal";
export { installWidgetPreviewRequests, isPendingResponse, widgetPreviewRequestKey } from "../preview/widget-preview-requests";
export { installWidgetPreviewHydration } from "../preview/widget-preview-hydration";
export { buildPreviewComposedViewComponents } from "../preview/preview-stub-components";
