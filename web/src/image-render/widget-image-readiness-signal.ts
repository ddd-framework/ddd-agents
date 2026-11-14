import { getSandboxLegacyAliases } from "../provider/environment";
import type { WidgetImageReadiness } from "./widget-image-render.types";

/** The `window` property the image capture waits on. */
export const WIDGET_IMAGE_READINESS_GLOBAL_NAME = "__AGENTS_WIDGET_IMAGE_V1__";

type WindowSignals = Record<string, WidgetImageReadiness | undefined>;

/**
 * Every name the image readiness signal is published under: the package's own, then each name the app declares
 * (`AgentsEnvironment.sandboxLegacyAliases.imageReadinessGlobalNames`) — read at each publish, so an app may declare
 * them after a readiness was created.
 */
export const readWidgetImageReadinessGlobalNames = (): string[] => [
  WIDGET_IMAGE_READINESS_GLOBAL_NAME,
  ...(getSandboxLegacyAliases().imageReadinessGlobalNames ?? []).filter(
    (aliasName) => aliasName !== WIDGET_IMAGE_READINESS_GLOBAL_NAME,
  ),
];

/** Publishes (or with `undefined` clears) the image readiness signal under every name. */
export const publishWidgetImageReadiness = (readiness: WidgetImageReadiness | undefined): void => {
  const windowSignals = window as unknown as WindowSignals;
  for (const globalName of readWidgetImageReadinessGlobalNames()) windowSignals[globalName] = readiness;
};

/**
 * The published image readiness signal. A `failed` value under ANY name wins — the app's own capture code may have
 * written it under a name it declared — otherwise the value under the package's name, otherwise the first value found.
 */
export const readWidgetImageReadiness = (): WidgetImageReadiness | undefined => {
  const windowSignals = window as unknown as WindowSignals;
  const publishedSignals = readWidgetImageReadinessGlobalNames()
    .map((globalName) => windowSignals[globalName])
    .filter((signal): signal is WidgetImageReadiness => signal !== undefined && signal !== null);
  return publishedSignals.find((signal) => signal.state === "failed") ?? publishedSignals[0];
};
