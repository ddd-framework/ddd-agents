/**
 * The modern widget design primitives — the one place a chat widget or a kit component takes its look
 * from. The KPI hero scale, the widget text and the status chip are replaceable components of the provider (the app
 * supplies its dashboard's primitives), re-exported here so the chat never grows a second type scale.
 */
export { WidgetSurface } from "./WidgetSurface";
export type { WidgetSurfaceLevel, WidgetSurfaceProps } from "./WidgetSurface";
export { WidgetKpiValue } from "./WidgetKpiValue";
export type { WidgetKpiValueProps } from "./WidgetKpiValue";
export { WidgetListRow } from "./WidgetListRow";
export type { WidgetListRowProps } from "./WidgetListRow";
export { WidgetStatusChip } from "./WidgetStatusChip";
export type { WidgetStatusChipProps, WidgetStatusTone } from "./WidgetStatusChip";
export { WidgetSurfaceSkeleton } from "./WidgetSurfaceSkeleton";
export type { WidgetSurfaceSkeletonProps } from "./WidgetSurfaceSkeleton";
export { HeroValue, WidgetText } from "../../../provider/components";
