import {
  conversationWidgetRegistry,
  replaceConversationWidgetRegistryEntries,
  type ConversationWidgetEntry,
} from "../../renderer/conversationWidgetRegistry";

export interface TestConversationWidgetsOptions {
  /**
   * Keeps the registered widgets beside the test widgets (a test widget replaces the registered one of its wire name).
   * Default false: the registry holds the test widgets only, so an app's registrations made with its environment land
   * nowhere while the installation lasts.
   */
  keepRegisteredWidgets?: boolean;
}

/**
 * The registry entries a spec renders against, and the way back: `restore()` puts the entries the registry held before
 * the installation. Everything that reads the registry — the widget host, the name resolver, the markdown renderer, an
 * embedded widget — sees the test widgets while it lasts.
 */
export class TestConversationWidgetsInstallation {
  protected readonly previousWidgets: ConversationWidgetEntry[];

  constructor(widgets: readonly ConversationWidgetEntry[], options: TestConversationWidgetsOptions = {}) {
    const testWidgetNames = new Set(widgets.map((widget) => widget.name));
    const keptWidgets = options.keepRegisteredWidgets
      ? conversationWidgetRegistry.widgets.filter((widget) => !testWidgetNames.has(widget.name))
      : [];
    this.previousWidgets = replaceConversationWidgetRegistryEntries([...keptWidgets, ...widgets]);
  }

  /** Puts the entries back that the registry held before the installation. */
  restore(): void {
    replaceConversationWidgetRegistryEntries(this.previousWidgets);
  }
}

/**
 * Renders a spec against widgets of its own instead of mocking the registry module: the test widgets take the
 * registry's place (or join it, `keepRegisteredWidgets`) until `restore()`.
 */
export const installTestConversationWidgets = (
  widgets: readonly ConversationWidgetEntry[],
  options: TestConversationWidgetsOptions = {},
): TestConversationWidgetsInstallation => new TestConversationWidgetsInstallation(widgets, options);
