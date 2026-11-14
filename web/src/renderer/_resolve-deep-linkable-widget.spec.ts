import { describe, expect, test } from "vitest";
import { deepLinkableWidgetNameForCanvas, resolveDeepLinkableWidget } from "./resolve-deep-linkable-widget";
import { conversationWidgetRegistry, registerConversationWidgets } from "./conversationWidgetRegistry";

/**
 * `ExampleEditor` stands for an app widget a page may mount by name (it opens the app's `exampleWorkSurface` canvas);
 * `ExampleEditorSelection` for an app widget the customer composes, never mounted by name. The package's own widgets
 * are none of them deep-linkable.
 */
registerConversationWidgets([
  {
    name: "ExampleEditor",
    component: () => null,
    deepLinkable: true,
    opensCanvases: ["exampleWorkSurface"],
  },
  { name: "ExampleEditorSelection", component: () => null, composerComponent: () => null },
]);

describe("resolveDeepLinkableWidget", () => {
  test("resolves a widget the registry marks deep-linkable", () => {
    const entry = resolveDeepLinkableWidget("ExampleEditor");
    expect(entry?.name).toBe("ExampleEditor");
    expect(entry?.component).toBeTypeOf("function");
  });

  test("refuses a registered widget that is NOT deep-linkable — a URL may not mount an arbitrary widget", () => {
    const notDeepLinkable = conversationWidgetRegistry.widgets.find((entry) => entry.deepLinkable !== true);
    expect(notDeepLinkable).toBeDefined();
    expect(resolveDeepLinkableWidget(notDeepLinkable?.name)).toBeNull();
    // A widget the customer composes in the chat input is never mounted by name.
    expect(resolveDeepLinkableWidget("ExampleEditorSelection")).toBeNull();
    // A package widget is not deep-linkable.
    expect(resolveDeepLinkableWidget("ConversationSearchResults")).toBeNull();
  });

  test("an unknown, empty or missing name resolves to nothing", () => {
    expect(resolveDeepLinkableWidget("NoSuchWidget")).toBeNull();
    expect(resolveDeepLinkableWidget("")).toBeNull();
    expect(resolveDeepLinkableWidget(undefined)).toBeNull();
    expect(resolveDeepLinkableWidget(null)).toBeNull();
  });

  test("every deep-linkable entry declares a component — the mount path has nothing else to render", () => {
    const deepLinkable = conversationWidgetRegistry.widgets.filter((entry) => entry.deepLinkable === true);
    expect(deepLinkable.length).toBeGreaterThan(0);
    deepLinkable.forEach((entry) => expect(entry.component).toBeTypeOf("function"));
  });
});

describe("deepLinkableWidgetNameForCanvas", () => {
  test("names the widget that re-opens the work surface — what a reload restores from", () => {
    expect(deepLinkableWidgetNameForCanvas("exampleWorkSurface")).toBe("ExampleEditor");
  });

  test("a canvas only the agent opens has no name to mirror into the URL", () => {
    expect(deepLinkableWidgetNameForCanvas("conversationSearchResults")).toBeNull();
    expect(deepLinkableWidgetNameForCanvas(null)).toBeNull();
    expect(deepLinkableWidgetNameForCanvas(undefined)).toBeNull();
  });

  test("round-trips: every canvas it names is opened by a widget the deep-link allow-list admits", () => {
    conversationWidgetRegistry.widgets
      .filter((entry) => entry.deepLinkable === true)
      .forEach((entry) => {
        entry.opensCanvases?.forEach((canvasId) => {
          const name = deepLinkableWidgetNameForCanvas(canvasId);
          expect(name).not.toBeNull();
          expect(resolveDeepLinkableWidget(name)).not.toBeNull();
        });
      });
  });
});
