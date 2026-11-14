// `@mgamadeus/ddd-agents/image-render` — the static widget image render (the e-mail capture): the host, its readiness,
// the frozen and live request stand-ins and the capture-only component registry.
export {
  WidgetImageRender,
  WidgetImageCaptureBoundary,
  followWidgetHydrationReadiness,
  registerStaticWidgetImageComponents,
  staticWidgetImageComponents,
} from "../image-render/WidgetImageRender";
export * from "../image-render/WidgetImageRenderContext";
export * from "../image-render/widget-image-render.types";
export { createWidgetImageReadiness } from "../image-render/widget-image-readiness";
export {
  publishWidgetImageReadiness,
  readWidgetImageReadiness,
  readWidgetImageReadinessGlobalNames,
  WIDGET_IMAGE_READINESS_GLOBAL_NAME,
} from "../image-render/widget-image-readiness-signal";
export { installFrozenWidgetRequests } from "../image-render/frozen-widget-requests";
export { installLiveWidgetRequests } from "../image-render/live-widget-requests";
export { isGoogleMapCapture } from "../image-render/widget-image-google-map";
export { readComposedViewDataTimes, type ComposedViewDataTimes } from "../image-render/composed-view-data-times";
