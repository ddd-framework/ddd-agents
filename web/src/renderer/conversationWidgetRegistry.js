import { SuggestedActions } from "../widgets/SuggestedActions/SuggestedActions";
import { MultiChoiceConversationWidget } from "../widgets/MultiChoiceConversationWidget/MultiChoiceConversationWidget";
import { QuickRepliesConversationWidget } from "../widgets/QuickRepliesConversationWidget/QuickRepliesConversationWidget";
import { ToolTraceConversationWidget } from "../widgets/ToolTraceConversationWidget/ToolTraceConversationWidget";
import { ConversationSearchResultsConversationWidget } from "../widgets/ConversationSearchResultsConversationWidget/ConversationSearchResultsConversationWidget";
import { WebSearchResultsConversationWidget } from "../widgets/WebSearchResultsConversationWidget/WebSearchResultsConversationWidget";
/**
 * The package's widgets that register themselves by FILE: every `*.widget-registration.ts` below the
 * package's `widgets/` and `kit/` that exports `conversationWidgetRegistration` joins the list. An app registers its
 * own widgets with `registerConversationWidgets` (its folder globs stay in its own code).
 */
const widgetRegistrationModules = import.meta.glob(["../widgets/**/*.widget-registration.ts", "../kit/**/*.widget-registration.ts"], { eager: true });
/** The entries of a set of registration modules (`*.widget-registration.ts` files an app or the package globbed). */
export const readConversationWidgetRegistrations = (registrationModules) => Object.values(registrationModules)
    .map((registrationModule) => registrationModule.conversationWidgetRegistration)
    .filter((entry) => entry !== undefined);
/** The package's own widgets registered by file. */
export const readFolderRegisteredConversationWidgets = () => readConversationWidgetRegistrations(widgetRegistrationModules);
/** Throws on a wire name registered twice — the written list and the registration files together. */
export const assertUniqueConversationWidgetNames = (widgets) => {
    const seenNames = new Set();
    for (const widget of widgets) {
        if (seenNames.has(widget.name))
            throw new Error(`The conversation widget "${widget.name}" is registered twice.`);
        seenNames.add(widget.name);
    }
};
/** The package's own widgets: the written entries and every package widget registered by file. */
export const packageConversationWidgets = [
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
export const conversationWidgetRegistry = {
    widgets: [...packageConversationWidgets],
};
assertUniqueConversationWidgetNames(conversationWidgetRegistry.widgets);
let registryRevision = 0;
/**
 * Counts the registrations: a reader that caches something derived from the registry (a name set, a component map)
 * rebuilds it when the revision moved.
 */
export const getConversationWidgetRegistryRevision = () => registryRevision;
/**
 * Adds an app's widgets to the registry. Runs outside React, before the first render; throws when a wire name is
 * registered twice (with the package's own or an earlier registration). An entry with `replacesPackageWidget` first
 * removes the package's entry of its name; it never replaces an app's earlier registration.
 */
export const registerConversationWidgets = (widgets) => {
    for (const widget of widgets) {
        if (widget.replacesPackageWidget !== true)
            continue;
        const packageEntryIndex = conversationWidgetRegistry.widgets.findIndex((entry) => entry.name === widget.name && packageConversationWidgets.includes(entry));
        if (packageEntryIndex >= 0)
            conversationWidgetRegistry.widgets.splice(packageEntryIndex, 1);
    }
    assertUniqueConversationWidgetNames([...conversationWidgetRegistry.widgets, ...widgets]);
    conversationWidgetRegistry.widgets.push(...widgets);
    registryRevision += 1;
};
/**
 * Puts `widgets` in place of every registered entry and returns the entries the registry held, so a spec renders
 * against widgets of its own and puts the registry back afterwards (`installTestConversationWidgets`, the
 * `client/testing` subpath). Fills the same array in place, so a reader that holds it sees the swap; moves the revision.
 */
export const replaceConversationWidgetRegistryEntries = (widgets) => {
    assertUniqueConversationWidgetNames(widgets);
    const previousWidgets = conversationWidgetRegistry.widgets.splice(0, conversationWidgetRegistry.widgets.length, ...widgets);
    registryRevision += 1;
    return previousWidgets;
};
