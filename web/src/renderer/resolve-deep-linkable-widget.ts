import type { CanvasId } from "./canvas/canvas-behaviours";
import { ConversationWidgetEntry, conversationWidgetRegistry } from "./conversationWidgetRegistry";

/**
 * Resolves the widget a `?agentWidget=<name>` deep-link (or a restored pinned canvas) may mount, by its CANONICAL
 * registry wire name.
 *
 * A URL is untrusted input, so this is an allow-list rather than a registry lookup: only an entry that declares
 * `deepLinkable: true` can be opened this way. Every other widget is emitted by the agent inside a message, where the
 * conversation itself is the authorisation — a link must not be able to mount an arbitrary widget (some carry
 * mount-time side effects) just because its name was typed into the address bar.
 *
 * Returns the entry (the caller renders its `component`, applying the feature gate the entry declares) or null when the
 * name is empty, unknown, or not deep-linkable.
 */
export const resolveDeepLinkableWidget = (widgetName?: string | null): ConversationWidgetEntry | null => {
  if (!widgetName) return null;
  return (
    conversationWidgetRegistry.widgets.find((entry) => entry.name === widgetName && entry.deepLinkable === true) ?? null
  );
};

/**
 * The reverse direction: which deep-linkable widget re-opens THIS canvas. The open canvas of the agent chat is
 * mirrored into `?agentWidget=` (AgentTrigger) so a reload brings the customer back to the surface they were working
 * on — an editor a cold chat opened has no conversation id yet, so the canvas model's own pointer storage
 * has nothing to restore from.
 *
 * Null for every canvas no deep-linkable widget declares: it is the agent's to open inside a message, and a URL must
 * not be able to mount it (the allow-list above).
 */
export const deepLinkableWidgetNameForCanvas = (canvasId: CanvasId | null | undefined): string | null => {
  if (!canvasId) return null;
  return (
    conversationWidgetRegistry.widgets.find(
      (entry) => entry.deepLinkable === true && entry.opensCanvases?.includes(canvasId),
    )?.name ?? null
  );
};
