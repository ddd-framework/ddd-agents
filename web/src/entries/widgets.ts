// `@mgamadeus/ddd-agents/widgets` — the generic widgets the package registers itself, the canvas card and the modern
// look primitives an app's own widgets build on.
export { CanvasWidgetCard, type CanvasWidgetCardProps } from "../widgets/CanvasWidgetCard/CanvasWidgetCard";
export { CanvasWidgetSwitch } from "../widgets/CanvasWidgetCard/CanvasWidgetSwitch";
export { canvasForMessageWidget } from "../widgets/CanvasWidgetCard/canvas-for-message-widget";
export { ClockConversationWidget } from "../widgets/ClockConversationWidget/ClockConversationWidget";
export { ConceptExplainerConversationWidget } from "../widgets/ConceptExplainerConversationWidget/ConceptExplainerConversationWidget";
export { ConversationSearchResultsConversationWidget } from "../widgets/ConversationSearchResultsConversationWidget/ConversationSearchResultsConversationWidget";
export { CurrencyConverterConversationWidget } from "../widgets/CurrencyConverterConversationWidget/CurrencyConverterConversationWidget";
export { CustomVisualizationConversationWidget } from "../widgets/CustomVisualizationConversationWidget/CustomVisualizationConversationWidget";
export { EntityMentionConversationWidget } from "../widgets/EntityMentionConversationWidget/EntityMentionConversationWidget";
export { MultiChoiceConversationWidget } from "../widgets/MultiChoiceConversationWidget/MultiChoiceConversationWidget";
export { QuickRepliesConversationWidget } from "../widgets/QuickRepliesConversationWidget/QuickRepliesConversationWidget";
export { SuggestedActions } from "../widgets/SuggestedActions/SuggestedActions";
export { ToolTraceConversationWidget } from "../widgets/ToolTraceConversationWidget/ToolTraceConversationWidget";
export { WebSearchResultsConversationWidget } from "../widgets/WebSearchResultsConversationWidget/WebSearchResultsConversationWidget";
export { WritingDraftConversationWidget } from "../widgets/WritingDraftConversationWidget/WritingDraftConversationWidget";
export * from "../widgets/_shared/modern";
// The primitives an app's own widgets build on beside the modern look: the label, the footer, the done pill, the
// skeleton shapes, payload validation, the canvas-body update channel, visibility, and the canvas viability.
export type { CanvasWidgetCardCanvas } from "../widgets/CanvasWidgetCard/CanvasWidgetCard";
export { CANVAS_VIABLE_MIN_WIDTH, useCanvasViable } from "../widgets/CanvasWidgetCard/use-canvas-viable";
export { WidgetLabel } from "../widgets/_shared/WidgetLabel";
export { CardFooter } from "../widgets/_shared/CardFooter";
export { DonePill } from "../widgets/_shared/DonePill";
export { SkeletonCircle, SkeletonLine, SkeletonPill, SkeletonWidgetLabel } from "../widgets/_shared/skeleton-primitives";
export {
  pickAllowed,
  pickAllowedValue,
  pickReviewExternalId,
  pickSafeAlias,
  pickSafeAliases,
  pickText,
} from "../widgets/_shared/widget-payload-validation";
export { createEntityUpdateChannel, type EntityUpdate } from "../widgets/_shared/entity-update-channel";
export { useElementVisible } from "../widgets/_shared/use-element-visible";
export { normalizeSearchHitExcerpt } from "../widgets/ConversationSearchResultsConversationWidget/shared/search-hit-excerpt";
// The parts of a concept explainer type an app writes (its definition is registered through the root entry).
export type {
  ConceptExplainerStep,
  ConceptExplainerStepsInput,
  ConceptExplainerText,
  ConceptExplainerValues,
  ConceptExplainerValuesReading,
  ConceptExplainerVariant,
  ConceptExplainerVisualProps,
} from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-type";
export {
  readConceptExplainerNumber,
  readConceptExplainerValue,
} from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-widget-payload";
export { formatConceptExplainerNumber } from "../widgets/ConceptExplainerConversationWidget/shared/use-concept-explainer-language";
