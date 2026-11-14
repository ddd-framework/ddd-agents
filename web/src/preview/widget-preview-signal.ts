import { getSandboxLegacyAliases } from "../provider/environment";
import type { WidgetPreviewSignal } from "./widget-preview.types";

/** The `window` property a preview script waits on. */
export const WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME = "__AGENTS_WIDGET_PREVIEW_V1__";

type WindowSignals = Record<string, WidgetPreviewSignal | undefined>;

/**
 * Every name the preview signal is published under: the package's own, then each name the app declares
 * (`AgentsEnvironment.sandboxLegacyAliases.previewReadinessGlobalNames`), read at each publish.
 */
export const readWidgetPreviewSignalGlobalNames = (): string[] => [
  WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME,
  ...(getSandboxLegacyAliases().previewReadinessGlobalNames ?? []).filter(
    (aliasName) => aliasName !== WIDGET_PREVIEW_SIGNAL_GLOBAL_NAME,
  ),
];

/** Publishes (or with `undefined` clears) the preview signal under every name. */
export const publishWidgetPreviewSignal = (signal: WidgetPreviewSignal | undefined): void => {
  const windowSignals = window as unknown as WindowSignals;
  for (const globalName of readWidgetPreviewSignalGlobalNames()) windowSignals[globalName] = signal;
};
