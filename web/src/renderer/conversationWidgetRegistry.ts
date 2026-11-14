import type React from "react";
import type { ConversationWidgetUpsellProps } from "./_access/ConversationWidgetFeatureGate";
import type { CanvasId } from "./canvas/canvas-behaviours";
import type { ComposerConversationWidgetProps } from "./conversation-widget-props";
import { SuggestedActions } from "../widgets/SuggestedActions/SuggestedActions";
import { MultiChoiceConversationWidget } from "../widgets/MultiChoiceConversationWidget/MultiChoiceConversationWidget";
import { QuickRepliesConversationWidget } from "../widgets/QuickRepliesConversationWidget/QuickRepliesConversationWidget";
import { ToolTraceConversationWidget } from "../widgets/ToolTraceConversationWidget/ToolTraceConversationWidget";
import { ConversationSearchResultsConversationWidget } from "../widgets/ConversationSearchResultsConversationWidget/ConversationSearchResultsConversationWidget";
import { WebSearchResultsConversationWidget } from "../widgets/WebSearchResultsConversationWidget/WebSearchResultsConversationWidget";

export interface ConversationWidgetEntry {
  /**
   * The CANONICAL wire name — short, never ending in `Directive` (that suffix is reserved as the legacy marker; see
   * `widget-name-resolver.ts`). Stored messages that carry the old `<Name>Directive` form resolve onto this one.
   */
  name: string;
  component: React.ComponentType<any>;
  /**
   * The app's requirement name (a feature flag) that gates this widget — opaque to the package, checked by the app's
   * `WidgetFeatureGate`. Omit to render unconditionally (default).
   */
  featureFlag?: string;
  /**
   * May this widget be mounted by NAME from outside a conversation — the `?agentWidget=<name>` deep-link a page uses to
   * open the chat with this widget's canvas already up, and the restore of a pinned canvas after a reload
   * (`resolve-deep-linkable-widget.ts`). Opt-in per widget because a URL is untrusted input: everything else is mounted
   * only from a message the agent wrote. Default false.
   */
  deepLinkable?: boolean;
  /**
   * The canvases (by canvas id, `canvas/canvas-behaviours.ts`) this widget may open. Documentation and the restore's
   * validation: a stored canvas pointer is re-opened only when its anchor message carries a widget that lists the
   * canvas. `_registry-wire-names.spec.ts` asserts every listed id is a declared canvas.
   */
  opensCanvases?: readonly CanvasId[];
  /** Optional custom upgrade offer for the gated widget; defaults to the app gate's own. */
  upsellTrigger?: React.ComponentType<ConversationWidgetUpsellProps>;
  /**
   * Renders this widget INSIDE the chat input (editable: per-part ✕ etc.). Present = the widget may be composed by
   * the customer (see `ConversationComposerWidget`). An entry declaring this MUST also declare `component` (asserted
   * in `_registry-wire-names.spec.ts`) — the message rendering.
   */
  composerComponent?: React.ComponentType<ComposerConversationWidgetProps>;
  /**
   * Backspace on an empty input removes the LAST removable part of the last composer widget. Returns the fields
   * without that part, or `null` when nothing is left (the widget is then removed entirely). Omitted → Backspace
   * removes the whole widget.
   */
  removeLastPartFromComposerWidgetFields?: (fields: Record<string, unknown>) => Record<string, unknown> | null;
}

/**
 * The package's widgets that register themselves by FILE: every `*.widget-registration.ts` below the
 * package's `widgets/` and `kit/` that exports `conversationWidgetRegistration` joins the list. An app registers its
 * own widgets with `registerConversationWidgets` (its folder globs stay in its own code).
 */
const widgetRegistrationModules = import.meta.glob<{ conversationWidgetRegistration?: ConversationWidgetEntry }>(
  ["../widgets/**/*.widget-registration.ts", "../kit/**/*.widget-registration.ts"],
  { eager: true },
);

/** The entries of a set of registration modules (`*.widget-registration.ts` files an app or the package globbed). */
export const readConversationWidgetRegistrations = (
  registrationModules: Record<string, { conversationWidgetRegistration?: ConversationWidgetEntry }>,
): ConversationWidgetEntry[] =>
  Object.values(registrationModules)
    .map((registrationModule) => registrationModule.conversationWidgetRegistration)
    .filter((entry): entry is ConversationWidgetEntry => entry !== undefined);

/** The package's own widgets registered by file. */
export const readFolderRegisteredConversationWidgets = (): ConversationWidgetEntry[] =>
  readConversationWidgetRegistrations(widgetRegistrationModules);

/** Throws on a wire name registered twice — the written list and the registration files together. */
export const assertUniqueConversationWidgetNames = (widgets: readonly ConversationWidgetEntry[]): void => {
  const seenNames = new Set<string>();
  for (const widget of widgets) {
    if (seenNames.has(widget.name)) throw new Error(`The conversation widget "${widget.name}" is registered twice.`);
    seenNames.add(widget.name);
  }
};

/** The package's own widgets: the written entries and every package widget registered by file. */
export const packageConversationWidgets: readonly ConversationWidgetEntry[] = [
  { name: "SuggestedActions", component: SuggestedActions },
  { name: "MultiChoice", component: MultiChoiceConversationWidget },
  { name: "QuickReplies", component: QuickRepliesConversationWidget },
  { name: "ToolTrace", component: ToolTraceConversationWidget },
  {
    name: "ConversationSearchResults",
    component: ConversationSearchResultsConversationWidget,
    opensCanvases: ["conversationSearchResults"],
  },
  { name: "WebSearchResults", component: WebSearchResultsConversationWidget, opensCanvases: ["webSearchResults"] },
  // Every package widget with a `<Name>ConversationWidget.widget-registration.ts` file joins below.
  ...readFolderRegisteredConversationWidgets(),
];

/**
 * ONE list — every registered widget, in no particular order. There are no kinds of widget here: inline vs canvas vs
 * mount-time is the component's own choice, not a registry bucket. A widget that opens a canvas lists the canvas ids
 * (`opensCanvases`); the canvas's behaviour lives in `canvas/canvas-behaviours.ts`, its body in `canvas/canvasBodies.tsx`.
 * It starts with the package's own widgets; an app adds its widgets with `registerConversationWidgets` before the
 * first render (outside React). The array is filled in place, so a reader that holds it sees every registration.
 */
export const conversationWidgetRegistry: {
  widgets: ConversationWidgetEntry[];
} = {
  widgets: [...packageConversationWidgets],
};

assertUniqueConversationWidgetNames(conversationWidgetRegistry.widgets);

let registryRevision = 0;

/**
 * Counts the registrations: a reader that caches something derived from the registry (a name set, a component map)
 * rebuilds it when the revision moved.
 */
export const getConversationWidgetRegistryRevision = (): number => registryRevision;

/**
 * Adds an app's widgets to the registry. Runs outside React, before the first render; throws when a wire name is
 * registered twice (with the package's own or an earlier registration).
 */
export const registerConversationWidgets = (widgets: readonly ConversationWidgetEntry[]): void => {
  assertUniqueConversationWidgetNames([...conversationWidgetRegistry.widgets, ...widgets]);
  conversationWidgetRegistry.widgets.push(...widgets);
  registryRevision += 1;
};
