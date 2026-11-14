import { getFurtherWidgetClickSignalSources } from "../provider/environment";

/**
 * The `signalSource`s a widget CLICK posts into the conversation (`injectSignal`): the generic widget click and the
 * dedicated source the former owner-action consent widget sent (no component posts it any more; stored rows keep it),
 * plus the further sources an app's own widgets post (`AgentsEnvironment.furtherWidgetClickSignalSources`, e.g. a form
 * submit that starts its own owner turn). The message list reads them to tell an answered widget from an open one after
 * a reload — a widget the owner already clicked must never come back interactive (a consent widget re-armed is a second
 * confirmation for a destructive tool). Add a source here when a widget of the package starts posting a new one.
 */
export const FRONTEND_WIDGET_CLICK_SIGNAL_SOURCE = "FRONTEND_WIDGET_CLICK";
export const OWNER_ACTION_CONFIRM_SIGNAL_SOURCE = "OWNER_ACTION_CONFIRM";

/** The package's own widget click sources (the backend's sources of the package that carry an owner statement). */
export const PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES: readonly string[] = [
  FRONTEND_WIDGET_CLICK_SIGNAL_SOURCE,
  OWNER_ACTION_CONFIRM_SIGNAL_SOURCE,
];

/** Every widget click source: the package's own and the app's further ones. */
export const getWidgetClickSignalSources = (): readonly string[] => [
  ...PACKAGE_WIDGET_CLICK_SIGNAL_SOURCES,
  ...getFurtherWidgetClickSignalSources(),
];

export const isWidgetClickSignalSource = (signalSource: string | undefined | null): boolean =>
  signalSource != null && getWidgetClickSignalSources().includes(signalSource);
