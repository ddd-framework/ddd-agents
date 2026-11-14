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
