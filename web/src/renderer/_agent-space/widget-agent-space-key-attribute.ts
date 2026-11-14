import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";

/** The key of a widget block's agent space as it arrives: a string from a markdown attribute, a number from a block field. */
export type WidgetAgentSpaceKeyAttribute = string | number | undefined;

/**
 * Reads the key of the agent space a widget block names: the value of the block field the app declares
 * (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`), among a widget's props or a block's fields. Undefined when the
 * block names none.
 */
export const readWidgetAgentSpaceKeyAttribute = (propsOrFields: object): WidgetAgentSpaceKeyAttribute => {
  const attribute = (propsOrFields as Record<string, unknown>)[getWidgetSelfHydrationKeyFieldName()];
  return typeof attribute === "string" || typeof attribute === "number" ? attribute : undefined;
};

/**
 * The props that hand a widget the key of its agent space under the block field the app declares, exactly as a
 * markdown attribute would carry it; no prop without a key.
 */
export const buildWidgetAgentSpaceKeyProps = (
  agentSpaceKeyAttribute: WidgetAgentSpaceKeyAttribute,
): Record<string, string | number> =>
  agentSpaceKeyAttribute === undefined || agentSpaceKeyAttribute === ""
    ? {}
    : { [getWidgetSelfHydrationKeyFieldName()]: agentSpaceKeyAttribute };
