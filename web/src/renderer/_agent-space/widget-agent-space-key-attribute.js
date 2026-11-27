import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
/**
 * Reads the key of the agent space a widget block names: the value of the block field the app declares
 * (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`), among a widget's props or a block's fields. Undefined when the
 * block names none.
 */
export const readWidgetAgentSpaceKeyAttribute = (propsOrFields) => {
    const attribute = propsOrFields[getWidgetSelfHydrationKeyFieldName()];
    return typeof attribute === "string" || typeof attribute === "number" ? attribute : undefined;
};
/**
 * The props that hand a widget the key of its agent space under the block field the app declares, exactly as a
 * markdown attribute would carry it; no prop without a key.
 */
export const buildWidgetAgentSpaceKeyProps = (agentSpaceKeyAttribute) => agentSpaceKeyAttribute === undefined || agentSpaceKeyAttribute === ""
    ? {}
    : { [getWidgetSelfHydrationKeyFieldName()]: agentSpaceKeyAttribute };
