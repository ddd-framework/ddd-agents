import { getSandboxLegacyAliases } from "../provider/environment";
/** The `window` property the image capture waits on. */
export const WIDGET_IMAGE_READINESS_GLOBAL_NAME = "__AGENTS_WIDGET_IMAGE_V1__";
/**
 * Every name the image readiness signal is published under: the package's own, then each name the app declares
 * (`AgentsEnvironment.sandboxLegacyAliases.imageReadinessGlobalNames`) — read at each publish, so an app may declare
 * them after a readiness was created.
 */
export const readWidgetImageReadinessGlobalNames = () => [
    WIDGET_IMAGE_READINESS_GLOBAL_NAME,
    ...(getSandboxLegacyAliases().imageReadinessGlobalNames ?? []).filter((aliasName) => aliasName !== WIDGET_IMAGE_READINESS_GLOBAL_NAME),
];
/** Publishes (or with `undefined` clears) the image readiness signal under every name. */
export const publishWidgetImageReadiness = (readiness) => {
    const windowSignals = window;
    for (const globalName of readWidgetImageReadinessGlobalNames())
        windowSignals[globalName] = readiness;
};
/**
 * The published image readiness signal. A `failed` value under ANY name wins — the app's own capture code may have
 * written it under a name it declared — otherwise the value under the package's name, otherwise the first value found.
 */
export const readWidgetImageReadiness = () => {
    const windowSignals = window;
    const publishedSignals = readWidgetImageReadinessGlobalNames()
        .map((globalName) => windowSignals[globalName])
        .filter((signal) => signal !== undefined && signal !== null);
    return publishedSignals.find((signal) => signal.state === "failed") ?? publishedSignals[0];
};
