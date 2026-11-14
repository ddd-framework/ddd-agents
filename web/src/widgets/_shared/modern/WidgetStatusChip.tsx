import { StatusChip, type AgentsStatusChipProps, type AgentsStatusChipTone } from "../../../provider/components";

export type WidgetStatusTone = AgentsStatusChipTone;

export type WidgetStatusChipProps = AgentsStatusChipProps;

/**
 * The status chip of the chat widgets: the `StatusChip` component (in the app: the dashboard's status
 * pill), so the chat and the dashboard read the same.
 */
export const WidgetStatusChip = (props: WidgetStatusChipProps) => <StatusChip {...props} />;
