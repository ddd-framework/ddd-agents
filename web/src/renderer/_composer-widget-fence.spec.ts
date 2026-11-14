import { describe, expect, it, vi } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import {
  buildComposerWidgetFenceBlock,
  buildMessageTextWithComposerWidgets,
  splitComposerWidgetFencesFromMessageText,
} from "./composer-widget-fence";
import { ConversationComposerWidget } from "./conversation-composer-widget";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";

// A minimal fake registry: one widget IS composable (declares `composerComponent`), one registered widget is NOT
// (a list widget that renders in messages but was never meant to live in the composer) — so the split
// function's "only composable fences are extracted" rule is exercised without depending on a real widget shipping.
vi.mock("./conversationWidgetRegistry", () => ({
  conversationWidgetRegistry: {
    widgets: [
      { name: "TestComposable", component: () => null, composerComponent: () => null },
      { name: "TestNonComposable", component: () => null },
    ],
  },
  // The app's registrations (installed with the environment) land nowhere in this stand-in registry.
  registerConversationWidgets: () => {},
  readConversationWidgetRegistrations: () => [],
  getConversationWidgetRegistryRevision: () => 0,
}));

const composerWidget = (overrides: Partial<ConversationComposerWidget> = {}): ConversationComposerWidget => ({
  composerWidgetId: "fixed-id",
  widgetName: "TestComposable",
  fields: { label: "Hero / Heading" },
  ...overrides,
});

describe("buildComposerWidgetFenceBlock", () => {
  it("builds a ```widget fence with `type` first, then the widget's own fields", () => {
    const block = buildComposerWidgetFenceBlock(composerWidget());
    expect(block).toBe('```widget\n{"type":"TestComposable","label":"Hero / Heading"}\n```');
  });

  it("a built fence parsed by remarkWidgetFencePlugin resolves the SAME widgetName", () => {
    const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
    const tree = processor.runSync(processor.parse(buildComposerWidgetFenceBlock(composerWidget())));
    let name: string | undefined;
    visit(tree, "leafDirective", (node: any) => {
      name ??= node.name;
    });
    expect(name).toBe("TestComposable");
  });
});

describe("buildMessageTextWithComposerWidgets", () => {
  it("joins typed text and one fence block per widget with a blank line, type first", () => {
    const text = buildMessageTextWithComposerWidgets("Hi", [composerWidget()]);
    expect(text).toBe('Hi\n\n```widget\n{"type":"TestComposable","label":"Hero / Heading"}\n```');
  });

  it("allows a widget-only send (empty typed text)", () => {
    const text = buildMessageTextWithComposerWidgets("", [composerWidget()]);
    expect(text).toBe('```widget\n{"type":"TestComposable","label":"Hero / Heading"}\n```');
  });

  it("keeps composer order across multiple widgets", () => {
    const text = buildMessageTextWithComposerWidgets("", [
      composerWidget({ composerWidgetId: "a", fields: { label: "First" } }),
      composerWidget({ composerWidgetId: "b", fields: { label: "Second" } }),
    ]);
    expect(text).toBe(
      '```widget\n{"type":"TestComposable","label":"First"}\n```' +
        "\n\n" +
        '```widget\n{"type":"TestComposable","label":"Second"}\n```',
    );
  });

  it("returns an empty string when both text and widgets are empty — the send guard's no-op cue", () => {
    expect(buildMessageTextWithComposerWidgets("  ", [])).toBe("");
  });

  it("trims the typed text", () => {
    expect(buildMessageTextWithComposerWidgets("  Hi  ", [])).toBe("Hi");
  });
});

describe("splitComposerWidgetFencesFromMessageText", () => {
  it("extracts a composable fence and strips it from the text", () => {
    const { text, widgets } = splitComposerWidgetFencesFromMessageText(
      'Make this headline punchier.\n\n```widget\n{"type":"TestComposable","label":"Hero / Heading"}\n```',
    );
    expect(text).toBe("Make this headline punchier.");
    expect(widgets).toEqual([
      { composerWidgetId: "TestComposable:0", widgetName: "TestComposable", fields: { label: "Hero / Heading" } },
    ]);
  });

  it("resolves the legacy `Directive`-suffixed type onto the canonical widgetName", () => {
    const { widgets } = splitComposerWidgetFencesFromMessageText(
      '```widget\n{"type":"TestComposableDirective","label":"Hero"}\n```',
    );
    expect(widgets).toEqual([
      { composerWidgetId: "TestComposable:0", widgetName: "TestComposable", fields: { label: "Hero" } },
    ]);
  });

  it("leaves a fence for a registered but NON-composable widget untouched in the text", () => {
    const message = '```widget\n{"type":"TestNonComposable"}\n```';
    const { text, widgets } = splitComposerWidgetFencesFromMessageText(message);
    expect(text).toBe(message);
    expect(widgets).toEqual([]);
  });

  it("leaves an unknown widget type's fence untouched", () => {
    const message = '```widget\n{"type":"NotAWidget"}\n```';
    const { text, widgets } = splitComposerWidgetFencesFromMessageText(message);
    expect(text).toBe(message);
    expect(widgets).toEqual([]);
  });

  it("leaves malformed JSON inside a widget fence untouched (never throws)", () => {
    const message = "```widget\n{ not json\n```";
    expect(() => splitComposerWidgetFencesFromMessageText(message)).not.toThrow();
    expect(splitComposerWidgetFencesFromMessageText(message).text).toBe(message);
  });

  it("returns no widgets for plain prose", () => {
    expect(splitComposerWidgetFencesFromMessageText("Just a normal message.")).toEqual({
      text: "Just a normal message.",
      widgets: [],
    });
  });

  it("round-trips through build then split (ignoring the regenerated composerWidgetId)", () => {
    const widgets = [composerWidget({ fields: { label: "Hero / Heading" } })];
    const built = buildMessageTextWithComposerWidgets("Hi", widgets);
    const { text, widgets: splitWidgets } = splitComposerWidgetFencesFromMessageText(built);
    expect(text).toBe("Hi");
    expect(splitWidgets.map((w) => ({ widgetName: w.widgetName, fields: w.fields }))).toEqual(
      widgets.map((w) => ({ widgetName: w.widgetName, fields: w.fields })),
    );
  });
});
