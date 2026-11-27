// `@mgamadeus/ddd-agents/renderer` — the message renderer, the widget host and its contexts, the registry, the canvas
// model, hydration and the render report.
export { ConversationMarkdown } from "../renderer/ConversationMarkdown";
// The link of a chat message, for a host `Markdown` replacement that wraps it (`components.a`).
export { MarkdownLink } from "../renderer/MarkdownLink";
export { ConversationWidgetHost } from "../renderer/ConversationWidgetHost";
export { ConversationWidgetErrorBoundary } from "../renderer/ConversationWidgetErrorBoundary";
export { ConversationWidgetFeatureGate, } from "../renderer/_access/ConversationWidgetFeatureGate";
// The widget callbacks without the chat. `ConversationWidgetProvider` (bound to the chat's state partition) joins with
// the chat subpath.
export * from "../renderer/ConversationWidgetCallbacksContext";
export { conversationWidgetRegistry, getConversationWidgetRegistryRevision, readConversationWidgetRegistrations, } from "../renderer/conversationWidgetRegistry";
export { closestWidgetNames, resolveCanonicalWidgetName, resolveWidgetName } from "../renderer/widget-name-resolver";
export * from "../renderer/render-report/widget-render-report";
export * from "../renderer/widget-hydration";
// The counterpart of `registerWidgetHydrator`: a host that layers its own hydrators (a story) takes them off again.
export { unregisterWidgetHydrator } from "../renderer/widget-hydration/widget-hydration.registry";
export * from "../renderer/canvas/canvas-behaviours";
// The canvas pane's title slot (`titleBesideCloseButton`): an app's canvas body portals its title into it.
export { CanvasTitleSlotContext, useCanvasTitleSlot } from "../renderer/canvas/canvas-title-slot-context";
export { buildComposerWidgetFenceBlock, buildMessageTextWithComposerWidgets, splitComposerWidgetFencesFromMessageText, } from "../renderer/composer-widget-fence";
export * from "../renderer/canvas/canvas-state";
export { canvasBodies, canvasFeatureFlagOf } from "../renderer/canvas/canvasBodies";
// The entity an `openEntity` action opens, for a host's own `onOpenEntity` of the composed-view renderer.
export { readOpenEntityActionTarget, } from "../renderer/canvas/entity-detail-instance";
export { composedViewTreeToWire, readComposedViewTree } from "../renderer/widget-tree/composed-view-tree";
export { addComposedNodeSchemaApplicationPart, composedNodeSchema, getComposedNodeSchema, } from "../renderer/widget-tree/composed-node-schema";
// What an app's own widgets and canvas bodies read: the presentation scope (conversation or e-mail capture), the
// action area, the agent space a block names, the anchor of a canvas, the widget variant, the canvas model a host's
// own chat state applies, the render report signal, and the deep-link allow-list.
export { ConversationWidgetRenderContext, useIsEmailWidgetRender } from "../renderer/ConversationWidgetRenderContext";
export { ConversationWidgetActions } from "../renderer/ConversationWidgetActions/ConversationWidgetActions";
export { ConversationWidgetGateSkeleton } from "../renderer/_access/ConversationWidgetGateSkeleton";
export { ConversationWidgetAgentSpaceFrame } from "../renderer/_agent-space/ConversationWidgetAgentSpaceFrame";
export { ConversationWidgetAgentSpaceHeader, } from "../renderer/_agent-space/ConversationWidgetAgentSpaceHeader";
export { useConversationWidgetAgentSpaceFallback } from "../renderer/_agent-space/use-conversation-widget-agent-space-fallback";
export { useResolvedConversationWidgetAgentSpaceKey, } from "../renderer/_agent-space/use-resolved-conversation-widget-agent-space-key";
export { buildWidgetAgentSpaceKeyProps, readWidgetAgentSpaceKeyAttribute, } from "../renderer/_agent-space/widget-agent-space-key-attribute";
export { findWidgetFenceByPayloadHash, findWidgetFencesInMarkdown, } from "../renderer/canvas/find-widget-fences-in-markdown";
export { useAnchorWidgetAgentSpaceKey, useAnchorWidgetFenceAgentSpaceKey } from "../renderer/canvas/use-anchor-widget-agent-space-key";
export { useAnchorWidgetPayload } from "../renderer/canvas/use-anchor-widget-payload";
export { applyClearCanvas, applyCloseCanvas, applyOpenCanvas, applyResetCanvasForNewTurn, applySetCanvasCreatedEntityId, } from "../renderer/canvas/canvas-reducers";
export { withConversationWidgetVariant } from "../renderer/read-conversation-widget-variant";
export { buildWidgetRenderReportSignal, parseWidgetRenderReportSignal, WIDGET_RENDER_REPORT_SIGNAL_SOURCE, } from "../renderer/render-report/widget-render-report-collector";
export { deepLinkableWidgetNameForCanvas, resolveDeepLinkableWidget } from "../renderer/resolve-deep-linkable-widget";
// The widget click sources (the package's own and the app's further ones), to tell an answered widget from an open one.
export { getWidgetClickSignalSources, isWidgetClickSignalSource } from "../renderer/widget-click-signal-sources";
