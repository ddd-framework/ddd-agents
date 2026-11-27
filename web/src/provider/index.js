export { AgentsProvider, useAgentsActiveAgentSpaceKey, useAgentsBudgetLimit, useAgentsComponents, useAgentsEnvironment, useAgentsMapsLoader, useAgentsOwnerSettings, useAgentsStateDispatch, useAgentsStateSelector, useAgentsTexts, } from "./AgentsProvider";
export { appendAnotherAgentSpaceToOwnerText, buildUnnamedAgentSpaceLabel, buildWidgetOfAnotherAgentSpaceExplanation, DEFAULT_AGENTS_TEXTS, DEFAULT_WIDGET_IMAGE_CAPTURE_READ_PATH_PREFIX, DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME, escapePlainTextForText, getAgentDisplayName, getAgentsEnvironment, getFurtherCanvasBehaviours, getFurtherWidgetClickSignalSources, getGoogleMapWidgetNames, getSandboxLegacyAliases, getWidgetImageCaptureReadPathPrefix, getWidgetSelfHydrationKeyFieldName, openImageGallery, reportRunningTurn, reportWidgetCrash, setAgentsEnvironment, translateText, } from "./environment";
export { classNames } from "./class-names";
// The conversation client `AgentsProvider` (or an `AgentChat` with a `client`) puts in reach of its subtree.
export { useAgentsConversationClient, useOptionalAgentsConversationClient, } from "../client/AgentsConversationClientContext";
// Links: the anchor every package link renders and the hook behind it (`AgentsEnvironment.onNavigate`).
export { AgentsLink, useAgentsLinkNavigation, } from "./link-navigation";
// The package's default components, for a host replacement that wraps one (`DEFAULT_AGENTS_COMPONENTS.Markdown`).
export { DEFAULT_AGENTS_COMPONENTS } from "./components/default-components";
// The package's colour scheme following a host theme kept as a class or an attribute on the root element.
export { AGENTS_THEME_ATTRIBUTE_NAME, followHostColorScheme, } from "./host-color-scheme";
export * from "./components";
