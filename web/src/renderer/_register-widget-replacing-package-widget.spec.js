import { afterEach, beforeEach, describe, expect, test } from "vitest";
import { conversationWidgetRegistry, packageConversationWidgets, registerConversationWidgets, replaceConversationWidgetRegistryEntries, } from "./conversationWidgetRegistry";
/**
 * An app that used a wire name before the package shipped a widget of that name keeps its own: an entry with
 * `replacesPackageWidget` takes the package's place; without the flag, and against an app's own earlier entry, the
 * second name still throws.
 */
describe("registerConversationWidgets with replacesPackageWidget", () => {
    let previousWidgets = [];
    beforeEach(() => {
        previousWidgets = replaceConversationWidgetRegistryEntries([...packageConversationWidgets]);
    });
    afterEach(() => {
        replaceConversationWidgetRegistryEntries(previousWidgets);
    });
    const AppImageGallery = () => null;
    test("an app entry with the flag takes the place of the package's entry of its name", () => {
        expect(conversationWidgetRegistry.widgets.some((entry) => entry.name === "ImageGallery")).toBe(true);
        registerConversationWidgets([{ name: "ImageGallery", component: AppImageGallery, replacesPackageWidget: true }]);
        const imageGalleryEntries = conversationWidgetRegistry.widgets.filter((entry) => entry.name === "ImageGallery");
        expect(imageGalleryEntries).toHaveLength(1);
        expect(imageGalleryEntries[0]?.component).toBe(AppImageGallery);
    });
    test("without the flag a name the package registers throws", () => {
        expect(() => registerConversationWidgets([{ name: "ImageGallery", component: AppImageGallery }])).toThrow('The conversation widget "ImageGallery" is registered twice.');
    });
    test("the flag never replaces an app's own earlier entry", () => {
        registerConversationWidgets([{ name: "ExampleNotes", component: () => null }]);
        expect(() => registerConversationWidgets([{ name: "ExampleNotes", component: () => null, replacesPackageWidget: true }])).toThrow('The conversation widget "ExampleNotes" is registered twice.');
    });
    test("the flag on a name the package does not register just adds the entry", () => {
        registerConversationWidgets([{ name: "ExampleOwnGallery", component: AppImageGallery, replacesPackageWidget: true }]);
        expect(conversationWidgetRegistry.widgets.filter((entry) => entry.name === "ExampleOwnGallery")).toHaveLength(1);
    });
});
