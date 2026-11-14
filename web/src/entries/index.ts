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
export {
  registerComposedViewDataSourceFetcher,
  type ComposedViewDataSourceFetcher,
} from "../renderer/widget-tree/composed-view-data-sources";
// Each registration with what it takes: the definition type and the reader of a folder's modules (an app's glob).
export {
  readEntityDetailBodyDefinitions,
  registerEntityDetailBodies,
  type EntityDetailBodyDefinition,
} from "../kit/entity-detail/entity-detail-bodies";
export {
  readConceptExplainerTypeDefinitions,
  registerConceptExplainerTypes,
} from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-types";
export type { ConceptExplainerTypeDefinition } from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-type";
