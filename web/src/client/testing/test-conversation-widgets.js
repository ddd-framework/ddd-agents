import { conversationWidgetRegistry, replaceConversationWidgetRegistryEntries, } from "../../renderer/conversationWidgetRegistry";
/**
 * The registry entries a spec renders against, and the way back: `restore()` puts the entries the registry held before
 * the installation. Everything that reads the registry — the widget host, the name resolver, the markdown renderer, an
 * embedded widget — sees the test widgets while it lasts.
 */
export class TestConversationWidgetsInstallation {
    previousWidgets;
    constructor(widgets, options = {}) {
        const testWidgetNames = new Set(widgets.map((widget) => widget.name));
        const keptWidgets = options.keepRegisteredWidgets
            ? conversationWidgetRegistry.widgets.filter((widget) => !testWidgetNames.has(widget.name))
            : [];
        this.previousWidgets = replaceConversationWidgetRegistryEntries([...keptWidgets, ...widgets]);
    }
    /** Puts the entries back that the registry held before the installation. */
    restore() {
        replaceConversationWidgetRegistryEntries(this.previousWidgets);
    }
}
/**
 * Renders a spec against widgets of its own instead of mocking the registry module: the test widgets take the
 * registry's place (or join it, `keepRegisteredWidgets`) until `restore()`.
 */
export const installTestConversationWidgets = (widgets, options = {}) => new TestConversationWidgetsInstallation(widgets, options);
