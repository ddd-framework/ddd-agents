/**
 * The modern widget design primitives — the one place a chat widget or a kit component takes its look
 * from. The KPI hero scale, the widget text and the status chip are replaceable components of the provider (the app
 * supplies its dashboard's primitives), re-exported here so the chat never grows a second type scale.
 */
export { WidgetSurface } from "./WidgetSurface";
export { WidgetKpiValue } from "./WidgetKpiValue";
export { WidgetListRow } from "./WidgetListRow";
export { WidgetStatusChip } from "./WidgetStatusChip";
export { WidgetSurfaceSkeleton } from "./WidgetSurfaceSkeleton";
export { HeroValue, WidgetText } from "../../../provider/components";
