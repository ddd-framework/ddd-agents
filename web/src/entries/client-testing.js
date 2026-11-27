// `@mgamadeus/ddd-agents/client/testing` — the public border an app's specs and stories stand in at: the in-memory
// conversation client over recorded conversations, hydration answered by the spec, a registry of test widgets, and the
// switch that makes the `Map` node act as if Google refused the Maps key (and its reset), and the stored pointer of a
// conversation's open canvas (written, read and cleared as the chat does).
export * from "../client/testing/createInMemoryAgentsConversationClient";
export { installWidgetHydrationStandIns, WidgetHydrationStandIns, } from "../client/testing/widget-hydration-stand-ins";
export { installTestConversationWidgets, TestConversationWidgetsInstallation, } from "../client/testing/test-conversation-widgets";
export { resetGoogleMapsAuthFailureForTests, simulateGoogleMapsAuthFailure, } from "../kit/components/map/use-google-maps-auth-failure";
export { clearStoredCanvas, readStoredCanvas, storeOpenCanvas, } from "../renderer/canvas/canvasStorage";
