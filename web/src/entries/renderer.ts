// `@mgamadeus/ddd-agents/renderer` — the message renderer, the widget host and its contexts, the registry, the canvas
// model, hydration and the render report.
export { ConversationMarkdown, type ConversationMarkdownProps } from "../renderer/ConversationMarkdown";
export { ConversationWidgetHost, type ConversationWidgetRenderProps } from "../renderer/ConversationWidgetHost";
export { ConversationWidgetErrorBoundary } from "../renderer/ConversationWidgetErrorBoundary";
export {
  type ConversationWidgetUpsellProps,
  ConversationWidgetFeatureGate,
} from "../renderer/_access/ConversationWidgetFeatureGate";
// The widget callbacks without the chat. `ConversationWidgetProvider` (bound to the chat's state partition) joins with
// the chat subpath.
export * from "../renderer/ConversationWidgetCallbacksContext";
export type {
  BaseConversationWidgetProps,
  ComposerConversationWidgetProps,
  PayloadConversationWidgetProps,
} from "../renderer/conversation-widget-props";
export {
  conversationWidgetRegistry,
  getConversationWidgetRegistryRevision,
  readConversationWidgetRegistrations,
} from "../renderer/conversationWidgetRegistry";
export { closestWidgetNames, resolveCanonicalWidgetName, resolveWidgetName } from "../renderer/widget-name-resolver";
export * from "../renderer/render-report/widget-render-report";
export * from "../renderer/widget-hydration";
export * from "../renderer/canvas/canvas-behaviours";
export * from "../renderer/canvas/canvas-state";
export { canvasBodies, canvasFeatureFlagOf, type CanvasBodyProps } from "../renderer/canvas/canvasBodies";
export { readComposedViewTree } from "../renderer/widget-tree/composed-view-tree";
export {
  addComposedNodeSchemaApplicationPart,
  composedNodeSchema,
  getComposedNodeSchema,
  type ComposedNodeSchema,
  type ComposedNodeSchemaApplicationPart,
} from "../renderer/widget-tree/composed-node-schema";
