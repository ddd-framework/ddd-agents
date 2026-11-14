// `@mgamadeus/ddd-agents/kit` — `ComposedView`: the widget, the tree renderer and the node component types.
export { ComposedViewConversationWidget } from "../kit/ComposedViewConversationWidget";
export { ComposedViewRenderer } from "../kit/ComposedViewRenderer";
export * from "../kit/composed-view-component-types";
export * from "../kit/composed-view-tones";
// The pieces an app's own canvas bodies and charts build on: model-written text, the embedded domain widget, the
// chart component with the kit's palette and number locale, and the entity-detail body contract.
export { ModelText, type ModelTextProps } from "../kit/ModelText/ModelText";
export {
  EmbeddedConversationWidget,
  type EmbeddedConversationWidgetProps,
} from "../kit/components/embedding/EmbeddedConversationWidget";
export { ApexChart } from "../kit/components/data/apex-chart-component";
export { useChartPalette } from "../kit/components/data/use-chart-palette";
export { useNumberLocale } from "../kit/components/data/use-number-locale";
export type { EntityDetailBodyProps } from "../kit/entity-detail/entity-detail-bodies";
export { EntityDetailNameReportContext, useReportEntityDetailName } from "../kit/entity-detail/entity-detail-name";
