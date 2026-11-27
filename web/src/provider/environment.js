let appEnvironment = {};
const environmentListeners = new Set();
/** Sets the app-wide environment. Call it once before the first render; a later call re-renders the readers. */
export const setAgentsEnvironment = (environment) => {
    appEnvironment = environment;
    for (const notifyListener of environmentListeners)
        notifyListener();
};
export const getAgentsEnvironment = () => appEnvironment;
export const subscribeToAgentsEnvironment = (listener) => {
    environmentListeners.add(listener);
    return () => {
        environmentListeners.delete(listener);
    };
};
const substitutePlaceholders = (key, replacements) => replacements
    ? key.replace(/%([A-Za-z0-9_]+)%/g, (placeholder, name) => Object.hasOwn(replacements, name) ? String(replacements[name]) : placeholder)
    : key;
/** The package default: the English key with its placeholders substituted. */
export const DEFAULT_AGENTS_TEXTS = { translate: substitutePlaceholders };
/** Translates an interface text through the app-wide environment, for a string a component hands on (a label). */
export const translateText = (key, replacements, context) => (appEnvironment.texts ?? DEFAULT_AGENTS_TEXTS).translate(key, replacements, context);
/** A plain text as the app's text components take it (`AgentsEnvironment.escapePlainTextForText`). */
export const escapePlainTextForText = (text) => appEnvironment.escapePlainTextForText ? appEnvironment.escapePlainTextForText(text) : text;
/** Opens an image gallery over the page (`AgentsEnvironment.openImageGallery`). */
export const openImageGallery = (images, startIndex) => {
    if (appEnvironment.openImageGallery) {
        appEnvironment.openImageGallery(images, startIndex);
        return;
    }
    const startImage = images[startIndex];
    if (!startImage)
        return;
    if (appEnvironment.onNavigate?.(startImage.url, null) === true)
        return;
    window.open(startImage.url, "_blank", "noopener,noreferrer");
};
/** Whether the app can start a conversation in the owner's personal space (`AgentsEnvironment.startConversationInPersonalSpace`). */
export const canStartConversationInPersonalSpace = () => appEnvironment.startConversationInPersonalSpace !== undefined;
/** Starts a conversation in the owner's personal space with `ownerText` as its first message; false when the app cannot. */
export const startConversationInPersonalSpace = (ownerText) => {
    if (!appEnvironment.startConversationInPersonalSpace)
        return false;
    appEnvironment.startConversationInPersonalSpace(ownerText);
    return true;
};
/** Whether the app can open the permissions of a connection (`AgentsEnvironment.openConnectionPermissions`). */
export const canOpenConnectionPermissions = () => appEnvironment.openConnectionPermissions !== undefined;
/** Opens the permissions of a connection in the app; false when the app cannot. */
export const openConnectionPermissions = (target) => {
    if (!appEnvironment.openConnectionPermissions)
        return false;
    appEnvironment.openConnectionPermissions(target);
    return true;
};
/** The block field of the package's backend default (`WidgetBlockFieldNames::WIDGET_SELF_HYDRATION_KEY`). */
export const DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME = "agentSpaceId";
/** The field of a widget block that carries the key of its agent space (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`). */
export const getWidgetSelfHydrationKeyFieldName = () => appEnvironment.widgetSelfHydrationKeyFieldName ?? DEFAULT_WIDGET_SELF_HYDRATION_KEY_FIELD_NAME;
/** The owner's text for a click on a widget of another agent space (`AgentsEnvironment.appendAnotherAgentSpaceToOwnerText`). */
export const appendAnotherAgentSpaceToOwnerText = (ownerText, anotherAgentSpaceKey) => appEnvironment.appendAnotherAgentSpaceToOwnerText
    ? appEnvironment.appendAnotherAgentSpaceToOwnerText(ownerText, anotherAgentSpaceKey)
    : ownerText;
/** The label of an agent space without a known name (`AgentsEnvironment.buildUnnamedAgentSpaceLabel`). */
export const buildUnnamedAgentSpaceLabel = (agentSpaceKey) => appEnvironment.buildUnnamedAgentSpaceLabel ? appEnvironment.buildUnnamedAgentSpaceLabel(agentSpaceKey) : `#${agentSpaceKey}`;
/** The sentence under a widget of another agent space (`AgentsEnvironment.buildWidgetOfAnotherAgentSpaceExplanation`). */
export const buildWidgetOfAnotherAgentSpaceExplanation = (agentSpaceLabel) => appEnvironment.buildWidgetOfAnotherAgentSpaceExplanation
    ? appEnvironment.buildWidgetOfAnotherAgentSpaceExplanation(agentSpaceLabel)
    : translateText("This widget shows data for %label%, not for what you currently have selected.", {
        label: agentSpaceLabel,
    });
/** The further sandbox and readiness names the app declares (`AgentsEnvironment.sandboxLegacyAliases`). */
export const getSandboxLegacyAliases = () => appEnvironment.sandboxLegacyAliases ?? {};
/** The package default of `AgentsEnvironment.widgetImageCaptureReadPathPrefix`. */
export const DEFAULT_WIDGET_IMAGE_CAPTURE_READ_PATH_PREFIX = "/api/";
/** The path prefix of the API reads a live widget image capture lets through (`AgentsEnvironment.widgetImageCaptureReadPathPrefix`). */
export const getWidgetImageCaptureReadPathPrefix = () => appEnvironment.widgetImageCaptureReadPathPrefix ?? DEFAULT_WIDGET_IMAGE_CAPTURE_READ_PATH_PREFIX;
/** The app's widgets that draw a Google map (`AgentsEnvironment.googleMapWidgetNames`). */
export const getGoogleMapWidgetNames = () => appEnvironment.googleMapWidgetNames ?? [];
/** The app's further widget click signal sources (`AgentsEnvironment.furtherWidgetClickSignalSources`). */
export const getFurtherWidgetClickSignalSources = () => appEnvironment.furtherWidgetClickSignalSources ?? [];
/** The canvases of the app's widgets (`AgentsEnvironment.furtherCanvasBehaviours`). */
export const getFurtherCanvasBehaviours = () => appEnvironment.furtherCanvasBehaviours ?? {};
/** The name the owner knows the agent by (`AgentsEnvironment.agentDisplayName`). */
export const getAgentDisplayName = () => appEnvironment.agentDisplayName ?? translateText("the assistant");
/** Reports a running turn of an open chat (`AgentsEnvironment.reportRunningTurn`); returns the release. */
export const reportRunningTurn = () => appEnvironment.reportRunningTurn?.();
/** Reports a crashed widget (`AgentsEnvironment.reportWidgetCrash`). */
export const reportWidgetCrash = (widgetName, error, errorInfo) => {
    appEnvironment.reportWidgetCrash?.(widgetName, error, errorInfo);
};
