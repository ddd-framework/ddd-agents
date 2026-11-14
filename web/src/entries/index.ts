// `@mgamadeus/ddd-agents` — the provider (environment, replaceable components, texts) and every registration function
// an app calls once before the first render.
// The package's global stylesheet (package variables with their defaults, the default components' look) joins the one
// `styles.css` the build writes; the module stylesheets join it through the components that import them.
import "../styles/agents.scss";
export * from "../provider";
export {
  registerConversationWidgets,
  type ConversationWidgetEntry,
} from "../renderer/conversationWidgetRegistry";
export { registerCanvasBodies } from "../renderer/canvas/canvasBodies";
export { registerCanvasFeatureFlags } from "../renderer/canvas/canvas-feature-flags";
export { registerWidgetHydrator } from "../renderer/widget-hydration/widget-hydration.registry";
export { registerComposedViewDataSourceFetcher } from "../renderer/widget-tree/composed-view-data-sources";
export { registerEntityDetailBodies } from "../kit/entity-detail/entity-detail-bodies";
export { registerConceptExplainerTypes } from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-types";
