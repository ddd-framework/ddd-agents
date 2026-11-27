import { afterEach, describe, expect, it } from "vitest";
import { conversationWidgetRegistry, getConversationWidgetRegistryRevision, } from "../../renderer/conversationWidgetRegistry";
import { resolveWidgetName } from "../../renderer/widget-name-resolver";
import { installTestConversationWidgets } from "./test-conversation-widgets";
const TestSnapshot = () => null;
const testWidget = { name: "TestSnapshotForSpec", component: TestSnapshot };
describe("installTestConversationWidgets", () => {
    let installation;
    afterEach(() => {
        installation?.restore();
        installation = undefined;
    });
    it("puts the test widgets in place of the registry and restore() brings the registered ones back", () => {
        const registeredNames = conversationWidgetRegistry.widgets.map((widget) => widget.name);
        const revisionBefore = getConversationWidgetRegistryRevision();
        installation = installTestConversationWidgets([testWidget]);
        expect(conversationWidgetRegistry.widgets.map((widget) => widget.name)).toEqual(["TestSnapshotForSpec"]);
        expect(getConversationWidgetRegistryRevision()).toBeGreaterThan(revisionBefore);
        expect(resolveWidgetName("TestSnapshotForSpec")).toBe("TestSnapshotForSpec");
        expect(resolveWidgetName(registeredNames[0])).toBeUndefined();
        installation.restore();
        installation = undefined;
        expect(conversationWidgetRegistry.widgets.map((widget) => widget.name)).toEqual(registeredNames);
        expect(resolveWidgetName("TestSnapshotForSpec")).toBeUndefined();
    });
    it("keeps the registered widgets beside the test widgets on request, a test widget replacing its namesake", () => {
        const registeredCount = conversationWidgetRegistry.widgets.length;
        const namesake = conversationWidgetRegistry.widgets[0];
        const replacement = { name: namesake.name, component: TestSnapshot };
        installation = installTestConversationWidgets([replacement, testWidget], { keepRegisteredWidgets: true });
        expect(conversationWidgetRegistry.widgets).toHaveLength(registeredCount + 1);
        expect(conversationWidgetRegistry.widgets.find((widget) => widget.name === namesake.name)?.component).toBe(TestSnapshot);
    });
});
