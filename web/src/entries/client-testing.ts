// `@mgamadeus/ddd-agents/client/testing` — the public border an app's specs and stories stand in at: the in-memory
// conversation client over recorded conversations, hydration answered by the spec, and a registry of test widgets.
export * from "../client/testing/createInMemoryAgentsConversationClient";
export {
  installWidgetHydrationStandIns,
  WidgetHydrationStandIns,
  type WidgetHydrationStandInAnswer,
} from "../client/testing/widget-hydration-stand-ins";
export {
  installTestConversationWidgets,
  TestConversationWidgetsInstallation,
  type TestConversationWidgetsOptions,
} from "../client/testing/test-conversation-widgets";
