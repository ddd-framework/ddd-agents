import {
  DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME,
  getWidgetSelfHydrationKeyFieldName,
} from "../../provider/environment";

const DEFAULT_FIELD_NAME_PATTERN = new RegExp(`\\b${DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME}\\b`, "g");

/**
 * A fixture of the package as the app reads it. The package's fixtures name the key of a block's agent space with the
 * package default field (`agentSpaceId`): as a key of an object, as a field in a block's JSON, as an attribute of a
 * block's markdown. An app that declares another field name (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`)
 * reads every occurrence under its own name, so a fixture is mapped once, when it is loaded. Without a declaration
 * the fixture is returned as it is.
 */
export const mapFixtureToDeclaredAgentSpaceKeyFieldName = <Fixture>(fixture: Fixture): Fixture => {
  const declaredFieldName = getWidgetSelfHydrationKeyFieldName();
  if (declaredFieldName === DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME || fixture === undefined) return fixture;
  return JSON.parse(JSON.stringify(fixture).replace(DEFAULT_FIELD_NAME_PATTERN, declaredFieldName)) as Fixture;
};
